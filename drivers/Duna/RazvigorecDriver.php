<?php

/**
 * Razvigorec fiscal printer driver.
 *
 * Plain-text file-based protocol — no sequence bytes, no binary encoding.
 * Razvigorec.exe reads the input file passed as a CLI argument.
 *
 * Command reference (from Komandi.txt):
 *   #F              open fiscal receipt
 *   #S              open storno receipt
 *   #Z              Z-report (close day)
 *   #X              X-report (control)
 *   #P              periodic report
 *   @{name};{vat};{price};{qty}   register item
 *   #G{amount}      cash payment (Gotovo)
 *   #K{amount}      card payment (Karticka)
 *   #M{cash};{card} mixed payment (cash + card)
 *
 * Amounts are in MKD (denari), formatted as integers (no decimal point).
 * Item prices use two decimal places as shown in the example files.
 */
class RazvigorecDriver implements PrinterDriver
{
    use ProcessRunner;
    use DunaResult;

    private string $inputFile;
    private string $execPath;
    private string $configFile;

    public function __construct(string $basePath, ?string $port = null, ?string $speed = null)
    {
        $razvigorecDir    = $basePath . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'razvigorec';
        $this->inputFile  = $razvigorecDir . DIRECTORY_SEPARATOR . 'Razvigorec.txt';
        $this->execPath   = $razvigorecDir . DIRECTORY_SEPARATOR . 'Razvigorec.exe';
        $this->configFile = $razvigorecDir . DIRECTORY_SEPARATOR . 'Razvigorec.ini';
        $this->initResultPaths($razvigorecDir);
        $this->applySerialSettings($port, $speed);
    }

    /**
     * Points Razvigorec.ini at a COM port chosen by the caller.
     *
     * Razvigorec.ini is not a keyed INI: the port is simply the first line
     * ("COM1" as shipped). Any further lines are preserved. Razvigorec.exe
     * exposes no baud-rate setting, so a speed is rejected rather than
     * silently dropped.
     */
    private function applySerialSettings(?string $port, ?string $speed): void
    {
        if ($speed !== null) {
            throw new \InvalidArgumentException('The Razvigorec driver has no speed setting; omit "speed".');
        }

        if ($port === null) {
            return;
        }

        $original = @file_get_contents($this->configFile);

        if ($original === false) {
            throw new \RuntimeException('Cannot read ' . $this->configFile);
        }

        $lines = preg_split('/\r\n|\n|\r/', rtrim($original, "\r\n"));

        if (($lines[0] ?? null) === $port) {
            return;
        }

        $lines[0] = $port;

        if (file_put_contents($this->configFile, implode("\r\n", $lines) . "\r\n", LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write ' . $this->configFile);
        }
    }

    public function fiscal(array $items, array $payments): void
    {
        $lines = ['#F'];

        foreach ($items as $item) {
            $lines[] = $this->itemLine($item);
        }

        $lines[] = $this->paymentLine($payments);

        $this->execute(implode("\r\n", $lines) . "\r\n");
    }

    public function closeDayReport(): void
    {
        $this->execute("#Z\r\n");
    }

    public function controlReport(): void
    {
        $this->execute("#X\r\n");
    }

    public function depositWithdrawMoney(float $amount): void
    {
        // Not documented in Razvigorec protocol; unsupported.
        throw new \RuntimeException('depositWithdrawMoney is not supported by the Razvigorec driver');
    }

    public function periodShortReport(string $from, string $to): void
    {
        // #P command — date format not documented; using DDMMYY,DDMMYY by convention.
        $this->execute('#P' . $this->toDate($from) . ',' . $this->toDate($to) . "\r\n");
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function itemLine(array $item): string
    {
        $name  = $this->itemName($item['name']);
        $vat   = $this->vatCode($item['vat'] ?? 'A');
        $price = number_format((float) $item['price'],           2, '.', '');
        $qty   = number_format((float) ($item['quantity'] ?? 1), 3, '.', '');

        return "@{$name};{$vat};{$price};{$qty}";
    }

    /**
     * Aggregates payments and builds #G / #K / #M line.
     * Multiple cash or card entries are summed.
     */
    private function paymentLine(array $payments): string
    {
        $cash = 0.0;
        $card = 0.0;

        foreach ($payments as $payment) {
            if ($payment['cash'] ?? true) {
                $cash += (float) $payment['amount'];
            } else {
                $card += (float) $payment['amount'];
            }
        }

        $cashInt = (int) round($cash);
        $cardInt = (int) round($card);

        if ($cashInt > 0 && $cardInt > 0) {
            return "#M{$cashInt};{$cardInt}";
        }
        if ($cardInt > 0) {
            return "#K{$cardInt}";
        }
        return "#G{$cashInt}";
    }

    /**
     * Maps API VAT letter to Razvigorec VAT letter (A→A, B→B, V→C, G→D).
     */
    private function vatCode(string $vat): string
    {
        return match ($vat) {
            'A' => 'A',
            'B' => 'B',
            'V' => 'C',
            'G' => 'D',
            default => throw new \InvalidArgumentException("Invalid VAT code: $vat"),
        };
    }

    /** Converts YYYY-MM-DD to DDMMYY. */
    private function toDate(string $date): string
    {
        [$year, $month, $day] = explode('-', $date);
        return $day . $month . substr($year, -2);
    }

    private function win1251(string $content): string
    {
        return mb_convert_encoding($content, 'Windows-1251', 'UTF-8');
    }

    /**
     * Prepares a product name for the printer: upper-cased, then Windows-1251.
     *
     * Every vendor reference file names its products in capitals and the
     * receipts these tills print come out in capitals, so anything else only
     * differs from what the customer ends up reading. strtoupper() is
     * byte-based and would leave Cyrillic untouched, so the case change is
     * mb_strtoupper() on the UTF-8 string and the encoding follows it.
     */
    private function itemName(string $name): string
    {
        return $this->win1251(mb_strtoupper($name, 'UTF-8'));
    }

    /**
     * Runs via runProcess() for the argument escaping and exit-code check.
     * Razvigorec.exe locates Razvigorec.ini and Result.out next to itself
     * (AppDomain.BaseDirectory), so unlike ecrprint.exe it does not depend on
     * the working directory.
     */
    private function execute(string $content): void
    {
        $this->clearFiles($this->resultFile);
        $logBefore = $this->snapshotFiles($this->errorLogDir);

        file_put_contents($this->inputFile, $content, LOCK_EX);

        $this->runProcess($this->execPath, [$this->inputFile]);
        $this->assertResultOk($logBefore);
    }
}
