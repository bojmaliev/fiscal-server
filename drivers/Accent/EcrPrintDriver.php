<?php

/**
 * Base class for ecrprint.exe file-based fiscal printer drivers.
 *
 * Encapsulates the shared transport layer:
 *   - file paths (seq.txt, ecrprint.in, ecrprint.exe)
 *   - sequence-byte cycling (33–255, wrapping to 32)
 *   - writing the command file and invoking the executable
 *   - UTF-8 → Windows-1251 encoding helper
 */
abstract class EcrPrintDriver implements PrinterDriver
{
    use ProcessRunner;

    protected const SEQ_MIN = 32;
    protected const SEQ_MAX = 255;

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
     * ecrprint.exe takes no arguments — it reads the file named by
     * <defaultInputFileName> in ecrprint.xml, and looks up both of those
     * relative to its working directory. runProcess() supplies bin/accent as
     * that working directory, which is where initPaths() writes ecrprint.in.
     */
    protected function execute(string $content): void
    {
        // ecrprint.exe never sets a process exit code, so the only evidence
        // that a print failed is ecrprint.err. Clear the output files first so
        // whatever exists afterwards is known to be from this run.
        $this->clearFiles($this->errFile, $this->outFile, $this->resultFile);

        file_put_contents($this->inputFile, $content, LOCK_EX);

        $this->runProcess($this->execPath);

        $error = $this->readResultFile($this->errFile);

        $this->logRun($content, $error);

        if ($error !== null) {
            $context = $this->readResultFile($this->resultFile)
                ?? $this->readResultFile($this->outFile);

            throw new \RuntimeException(
                'ecrprint reported: ' . $error . ($context === null ? '' : ' | ' . $context)
            );
        }
    }

    /**
     * Appends what went out and what came back to ecrprint.log.
     *
     * A print that fails quietly leaves nothing to look at afterwards: the exe
     * reports nothing on success, and the next run overwrites ecrprint.in. Off
     * the back of a till test that "did not print", this is the only way to
     * tell which model's dialect actually went down the wire.
     */
    private function logRun(string $content, ?string $error): void
    {
        $entry = sprintf(
            "[%s] %s port=%s speed=%s\n  sent: %s\n",
            date('Y-m-d H:i:s'),
            static::class,
            $this->effectivePort,
            $this->effectiveSpeed,
            // the batch ends in CRLF; no need for a dangling indent line
            preg_replace('/\n\s*$/', '', $this->readable($content))
        );

        $results = [
            'err' => $error,
            'out' => $this->readResultFile($this->outFile),
            'rs'  => $this->readResultFile($this->resultFile),
        ];

        foreach ($results as $name => $text) {
            if ($text !== null) {
                $entry .= sprintf("  %s : %s\n", $name, str_replace("\n", ' | ', $text));
            }
        }

        @file_put_contents($this->logFile, $entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Renders the command bytes for the log. Every field in this protocol is
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
                "\n"    => "\\n\n        ",
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
