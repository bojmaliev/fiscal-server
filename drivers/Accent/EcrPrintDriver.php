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

    protected function initPaths(string $basePath): void
    {
        $accentDir       = $basePath . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'accent';
        $this->seqFile   = $accentDir . DIRECTORY_SEPARATOR . 'seq.txt';
        $this->inputFile = $accentDir . DIRECTORY_SEPARATOR . 'ecrprint.in';
        $this->execPath  = $accentDir . DIRECTORY_SEPARATOR . 'ecrprint.exe';
    }

    /**
     * ecrprint.exe takes no arguments — it reads the file named by
     * <defaultInputFileName> in ecrprint.xml, and looks up both of those
     * relative to its working directory. runProcess() supplies bin/accent as
     * that working directory, which is where initPaths() writes ecrprint.in.
     */
    protected function execute(string $content): void
    {
        file_put_contents($this->inputFile, $content, LOCK_EX);
        $this->runProcess($this->execPath);
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
