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
    private string $inputFile;
    private string $execPath;

    public function __construct(string $basePath)
    {
        $this->inputFile = $basePath . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'razvigorec' . DIRECTORY_SEPARATOR . 'Razvigorec.txt';
        $this->execPath  = $basePath . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'razvigorec' . DIRECTORY_SEPARATOR . 'Razvigorec.exe';
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
        $name  = $this->win1251($item['name']);
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

    private function execute(string $content): void
    {
        file_put_contents($this->inputFile, $content, LOCK_EX);
        exec(escapeshellcmd($this->execPath) . ' ' . escapeshellarg($this->inputFile));
    }
}
