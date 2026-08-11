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
    }

    /**
     * Points ecrprint.xml at a COM port / baud rate chosen by the caller.
     *
     * None of the vendor executables accept the port as a command-line
     * argument, so the only way to steer them is to rewrite their config file
     * before the run. Both parameters are optional: when omitted the file is
     * left byte-for-byte alone, so a device configured by hand keeps working.
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

        if ($error !== null) {
            $context = $this->readResultFile($this->resultFile)
                ?? $this->readResultFile($this->outFile);

            throw new \RuntimeException(
                'ecrprint reported: ' . $error . ($context === null ? '' : ' | ' . $context)
            );
        }
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
