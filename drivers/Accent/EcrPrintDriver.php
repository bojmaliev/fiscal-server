<?php

/**
 * Base class for ecrprint.exe file-based fiscal printer drivers.
 *
 * Encapsulates the shared transport layer:
 *   - file paths (seq.txt, ecrprint.in, ecrprint.exe)
 *   - sequence-byte cycling (33–255, wrapping to 32)
 *   - writing the command file and invoking the executable
 *   - reading back what the printer made of each command
 *   - UTF-8 → Windows-1251 encoding helper
 */
abstract class EcrPrintDriver implements PrinterDriver
{
    use ProcessRunner;

    protected const SEQ_MIN = 32;
    protected const SEQ_MAX = 255;

    /**
     * The one EcrResultStatus that means ecrprint got a clean answer back. The
     * rest of its vocabulary — NAK_RECEIVED, TIMEOUT_READING,
     * WRONG_COMMAND_RESPONSE, GENERAL_ERROR, SYNTAX_ERROR, INVALID_RESPONSE,
     * UNKNOWN — are all ways of not having done so.
     */
    private const STATUS_OK = 'OK';

    /**
     * The verdict the printer opens its reply with when it refuses a command.
     * The ones that mean it obliged are 'P' and, for a payment, 'R'.
     */
    private const REPLY_REFUSED = 'F';

    /** ecrprint announces itself on stdout before doing anything. */
    private const BANNERS = ['Ecr DLL Version:', 'Ecr EXE Version:'];

    protected string $seqFile;
    protected string $inputFile;
    protected string $execPath;
    protected string $configFile;
    protected string $errFile;
    protected string $outFile;
    protected string $resultFile;
    protected string $logFile;

    /** What the exe will actually use, whether this request set it or not. */
    protected string $effectivePort  = '?';
    protected string $effectiveSpeed = '?';

    protected function initPaths(string $basePath): void
    {
        $accentDir        = $basePath . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'accent';
        $this->seqFile    = $accentDir . DIRECTORY_SEPARATOR . 'seq.txt';
        $this->inputFile  = $accentDir . DIRECTORY_SEPARATOR . 'ecrprint.in';
        $this->execPath   = $accentDir . DIRECTORY_SEPARATOR . 'ecrprint.exe';
        $this->configFile = $accentDir . DIRECTORY_SEPARATOR . 'ecrprint.xml';
        $this->errFile    = $accentDir . DIRECTORY_SEPARATOR . 'ecrprint.err';
        $this->outFile    = $accentDir . DIRECTORY_SEPARATOR . 'ecrprint.out';
        $this->resultFile = $accentDir . DIRECTORY_SEPARATOR . 'ecrprint.rs';
        $this->logFile    = $accentDir . DIRECTORY_SEPARATOR . 'ecrprint.log';
    }

    /**
     * Points ecrprint.xml at a COM port / baud rate chosen by the caller.
     *
     * None of the vendor executables accept the port as a command-line
     * argument, so the only way to steer them is to rewrite their config file
     * before the run. A null port leaves <port> byte-for-byte alone, so a till
     * someone wired up by hand keeps working; the concrete drivers, sharing
     * this one file between two models, always pass a speed of their own.
     *
     * Either way the file is rewritten only when a value actually differs.
     *
     * @param string|null $speed Literal baud rate for ecrprint (e.g. "9600").
     */
    protected function applySerialSettings(?string $port, ?string $speed): void
    {
        if ($port === null && $speed === null) {
            return;
        }

        $doc = new \DOMDocument();
        $doc->preserveWhiteSpace = true;

        if (!@$doc->load($this->configFile)) {
            throw new \RuntimeException('Cannot read ' . $this->configFile);
        }

        $changed = false;

        foreach (['port' => $port, 'speed' => $speed] as $tag => $value) {
            if ($value === null) {
                continue;
            }

            $node = $doc->getElementsByTagName($tag)->item(0);

            if ($node === null) {
                throw new \RuntimeException("<{$tag}> missing from " . $this->configFile);
            }

            if ($node->textContent !== $value) {
                $node->textContent = $value;
                $changed = true;
            }
        }

        if ($changed && file_put_contents($this->configFile, $doc->saveXML(), LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write ' . $this->configFile);
        }

        // Kept for the run log: the file is already parsed here, and the log is
        // only useful if it records the settings the exe really ran with.
        $this->effectivePort  = $this->configValue($doc, 'port');
        $this->effectiveSpeed = $this->configValue($doc, 'speed');
    }

    private function configValue(\DOMDocument $doc, string $tag): string
    {
        $node = $doc->getElementsByTagName($tag)->item(0);

        return $node === null ? '?' : $node->textContent;
    }

    /**
     * Sends one batch of commands and fails if the printer refused any of them.
     *
     * ecrprint.exe reads the file named by <defaultInputFileName> in
     * ecrprint.xml, and looks up both of those relative to its working
     * directory. runProcess() supplies bin/accent as that working directory,
     * which is where initPaths() writes ecrprint.in.
     */
    protected function execute(string $content): void
    {
        // The exe always exits 0, so nothing about a failed print is visible
        // from the outside: it prints the message of any exception it swallowed
        // to stdout, and leaves what the printer said about each command in
        // three files. Clear those first so whatever is there afterwards is
        // known to be from this run.
        $this->clearFiles($this->errFile, $this->outFile, $this->resultFile);

        file_put_contents($this->inputFile, $content, LOCK_EX);

        $console = $this->consoleError($this->runProcess($this->execPath));

        // ecrprint appends one entry to each of the three files per line it
        // read from ecrprint.in, in order — including a blank entry for a line
        // it skipped — so entry N in all three belongs to command N.
        $commands = $this->splitLines($content);
        $outcomes = $this->readResultLines($this->resultFile);
        $statuses = $this->readResultLines($this->errFile);
        $replies  = $this->readResultLines($this->outFile);

        $this->logRun($commands, $console, $outcomes, $statuses, $replies);

        if ($console !== null) {
            throw new \RuntimeException('ecrprint reported: ' . $console);
        }

        $rejected = $this->rejection($commands, $outcomes, $statuses, $replies);

        if ($rejected !== null) {
            throw new \RuntimeException($rejected);
        }
    }

    /**
     * Names the first command the printer did not carry out, or null.
     *
     * Two different things can go wrong, and they are reported in two
     * different files. ecrprint.rs covers the link: one EcrResultStatus per
     * command, written as "<value>, <name>" (`1, OK`), saying whether ecrprint
     * got a clean answer at all. A command that arrived intact and was then
     * *refused* still reads OK there — the refusal is in the data the printer
     * sent back, in ecrprint.out, which opens with a verdict field: `P` for
     * done, `R` for a payment quoting the change, and `F` followed by a code
     * and a message for a rejection (`F -1004 Bad input`).
     *
     * ecrprint.err is *not* an error channel despite the name: it holds the
     * printer's six status bytes for every command, successful ones included,
     * so a run that went perfectly still fills it. It is quoted here as
     * context and nothing more.
     *
     * @param string[] $commands One raw command line each.
     * @param string[] $outcomes EcrResultStatus per command.
     * @param string[] $statuses Six printer status bytes per command.
     * @param string[] $replies  Returned data per command.
     */
    private function rejection(array $commands, array $outcomes, array $statuses, array $replies): ?string
    {
        foreach ($outcomes as $index => $outcome) {
            // A blank entry is a line ecrprint skipped, not a rejected command:
            // it needs two characters (sequence byte + command code) to send
            // anything, and the FP700 batches end in a blank line.
            if ($outcome === '') {
                continue;
            }

            $reply = $replies[$index] ?? '';

            if (!$this->linkFailed($outcome) && !$this->refused($reply)) {
                continue;
            }

            $bytes = $statuses[$index] ?? '';

            return sprintf(
                'ecrprint: the printer rejected command %d of %d (%s) — %s (status %s)',
                $index + 1,
                count($commands),
                $this->readable($commands[$index] ?? ''),
                $this->linkFailed($outcome) ? $outcome : str_replace("\t", ' ', $reply),
                $bytes === '' ? 'none' : $bytes
            );
        }

        return null;
    }

    /** True when ecrprint never got a clean answer back for this command. */
    private function linkFailed(string $outcome): bool
    {
        $fields = array_map('trim', explode(',', $outcome));

        return end($fields) !== self::STATUS_OK;
    }

    /** True when the printer answered and its answer was a refusal. */
    private function refused(string $reply): bool
    {
        return explode("\t", $reply)[0] === self::REPLY_REFUSED;
    }

    /**
     * The part of ecrprint's console output that is a complaint, or null.
     *
     * It prints two version banners and nothing else when it runs to
     * completion. Anything further is the message of an exception it caught —
     * a missing ecr.dll, an unreadable ecrprint.xml, no ecrprint.in — after
     * which it still exits 0 and still writes three empty result files, so
     * this is the only evidence such a run ever happened.
     */
    private function consoleError(string $console): ?string
    {
        $complaints = array_filter(
            array_map('trim', $this->splitLines($console)),
            static function (string $line): bool {
                foreach (self::BANNERS as $banner) {
                    if (str_starts_with($line, $banner)) {
                        return false;
                    }
                }

                return $line !== '';
            }
        );

        return $complaints === [] ? null : implode(' | ', $complaints);
    }

    /**
     * Reads one of ecrprint's per-command files as a list of entries.
     *
     * @return string[]
     */
    private function readResultLines(string $path): array
    {
        clearstatcache(true, $path);

        if (!is_file($path)) {
            return [];
        }

        $raw = mb_convert_encoding((string) @file_get_contents($path), 'UTF-8', 'Windows-1251');

        return array_map('trim', $this->splitLines($raw));
    }

    /**
     * Splits text the way File.ReadAllLines does inside ecrprint — on any line
     * ending, with the final line terminator not counting as another line — so
     * an entry in its output files maps back to the command that produced it.
     *
     * Nothing is trimmed: a command line ends in a meaningful tab, and the log
     * is worth little if it does not show the field separators that were
     * actually sent.
     *
     * @return string[]
     */
    private function splitLines(string $raw): array
    {
        $lines = preg_split("/\r\n|\r|\n/", $raw);

        if (end($lines) === '') {
            array_pop($lines);
        }

        return $lines;
    }

    /**
     * Appends what went out and what came back to ecrprint.log.
     *
     * A print that fails quietly leaves nothing to look at afterwards: the next
     * run overwrites both ecrprint.in and all three result files. Off the back
     * of a till test that "did not print", this is the only way to tell which
     * model's dialect went down the wire and which command the printer balked
     * at.
     *
     * @param string[] $commands
     * @param string[] $outcomes
     * @param string[] $statuses
     * @param string[] $replies
     */
    private function logRun(
        array $commands,
        ?string $console,
        array $outcomes,
        array $statuses,
        array $replies
    ): void {
        $entry = sprintf(
            "[%s] %s port=%s speed=%s\n",
            date('Y-m-d H:i:s'),
            static::class,
            $this->effectivePort,
            $this->effectiveSpeed
        );

        if ($console !== null) {
            $entry .= '  console: ' . $console . "\n";
        }

        foreach ($commands as $index => $command) {
            // A command with no entry beside it was never sent: ecrprint stops
            // at the first failure when <stopOnFail> is on.
            $entry .= sprintf(
                "  %d sent %s\n    got  %s\n",
                $index + 1,
                $this->readable($command),
                $this->outcomeSummary($outcomes, $statuses, $replies, $index)
            );
        }

        @file_put_contents($this->logFile, $entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * @param string[] $outcomes
     * @param string[] $statuses
     * @param string[] $replies
     */
    private function outcomeSummary(array $outcomes, array $statuses, array $replies, int $index): string
    {
        if (!array_key_exists($index, $outcomes)) {
            return 'not sent';
        }

        $parts = [$outcomes[$index] === '' ? 'skipped' : $outcomes[$index]];

        foreach (['status' => $statuses, 'returned' => $replies] as $label => $file) {
            if (($file[$index] ?? '') !== '') {
                $parts[] = $label . ' ' . $file[$index];
            }
        }

        return implode(' | ', $parts);
    }

    /**
     * Renders one command's bytes for the log. Every field in this protocol is
     * delimited by whitespace and the sequence byte is often unprintable, so
     * escaping them is the whole point — a receipt is also mostly
     * Windows-1251 text that would not survive a plain text log.
     */
    private function readable(string $raw): string
    {
        return preg_replace_callback(
            '/[^\x20-\x7e]/',
            fn(array $m) => match ($m[0]) {
                "\t"    => '\t',
                "\r"    => '\r',
                "\n"    => '\n',
                default => sprintf('\x%02X', ord($m[0])),
            },
            $raw
        );
    }

    protected function nextSeq(): int
    {
        $seq = self::SEQ_MIN;

        if (file_exists($this->seqFile)) {
            $seq = (int) file_get_contents($this->seqFile);
        }

        $seq = ($seq >= self::SEQ_MAX) ? self::SEQ_MIN : $seq + 1;

        file_put_contents($this->seqFile, $seq, LOCK_EX);

        return $seq;
    }

    protected function win1251(string $content): string
    {
        return mb_convert_encoding($content, 'Windows-1251', 'UTF-8');
    }
}
