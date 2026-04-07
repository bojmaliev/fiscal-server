<?php

class FP700Driver extends EcrPrintDriver
{
    private const NL  = "\n";
    private const TAB = "\t";

    // chr(64) = '@' — marks a Macedonian product in the FP700 binary protocol
    private const MKD_ITEM = '@';

    public function __construct(string $basePath)
    {
        $this->initPaths($basePath);
    }

    public function fiscal(array $items, array $payments): void
    {
        $commands = [
            $this->cmd('0', '1,0000,1'),
            ...array_map(fn(array $item)    => $this->cmd('1', $this->itemData($item)),    $items),
            ...array_map(fn(array $payment) => $this->cmd('5', $this->paymentData($payment)), $payments),
            $this->cmd('8'),
        ];

        $this->execute(implode(self::NL, $commands));
    }

    public function closeDayReport(): void
    {
        $this->execute($this->cmd('E'));
    }

    public function controlReport(): void
    {
        $this->execute($this->cmd('E', '2'));
    }

    public function depositWithdrawMoney(float $amount): void
    {
        // FP700 'F' command: the sign of the amount determines direction
        $this->execute($this->cmd('F', number_format($amount, 2, '.', '')));
    }

    public function periodShortReport(string $from, string $to): void
    {
        // FP700 expects ddMMyy (e.g. 291025), input is YYYY-MM-DD
        $this->execute($this->cmd('O', $this->toFP700Date($from) . ',' . $this->toFP700Date($to)));
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function itemData(array $item): string
    {
        $name     = $this->win1251($item['name']);
        $vat      = $this->vatByte($item['vat'] ?? 'A');
        $price    = number_format((float)$item['price'],    2, '.', '');
        $quantity = number_format((float)($item['quantity'] ?? 1), 3, '.', '');
        $mkd      = ($item['mkd'] ?? false) ? self::MKD_ITEM : '';

        return $name . self::TAB . $mkd . $vat . $price . '*' . $quantity;
    }

    private function paymentData(array $payment): string
    {
        $mode   = ($payment['cash'] ?? true) ? 'P' : 'D';
        $amount = number_format((float)$payment['amount'], 3, '.', '');

        return self::TAB . $mode . $amount;
    }

    /**
     * Maps VAT letter to the FP700 binary VAT byte (Windows-1251 Cyrillic range).
     * А=chr(192), Б=chr(193), В=chr(194), Г=chr(195)
     */
    private function vatByte(string $vat): string
    {
        return match ($vat) {
            'A' => chr(192),
            'B' => chr(193),
            'V' => chr(194),
            'G' => chr(195),
            default => throw new \InvalidArgumentException("Invalid VAT code: $vat"),
        };
    }

    /** Converts YYYY-MM-DD to ddMMyy for the FP700 'O' command. */
    private function toFP700Date(string $date): string
    {
        [$year, $month, $day] = explode('-', $date);
        return $day . $month . substr($year, -2);
    }

    /** Builds a single FP700 binary command: [seqByte][cmdChar][data] */
    private function cmd(string $command, string $data = ''): string
    {
        return chr($this->nextSeq()) . $command . $data;
    }
}
