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
    protected const SEQ_MIN = 32;
    protected const SEQ_MAX = 255;

    protected string $seqFile;
    protected string $inputFile;
    protected string $execPath;

    protected function initPaths(string $basePath): void
    {
        $this->seqFile   = $basePath . DIRECTORY_SEPARATOR . 'seq.txt';
        $this->inputFile = $basePath . DIRECTORY_SEPARATOR . 'ecrprint.in';
        $this->execPath  = $basePath . DIRECTORY_SEPARATOR . 'ecrprint.exe';
    }

    protected function execute(string $content): void
    {
        file_put_contents($this->inputFile, $content, LOCK_EX);
        exec($this->execPath);
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
