<?php

class FP700Driver extends EcrPrintDriver
{
    use ItemDiscount;

    private const CRLF = "\r\n";
    private const TAB  = "\t";

    // chr(64) = '@' — marks a Macedonian product in the FP700 binary protocol
    private const MKD_ITEM = '@';

    // Both Accent models are driven through the same ecrprint.xml, so each
    // driver states the rate its own printer runs at rather than trusting
    // whatever the other one left in the file.
    private const DEFAULT_SPEED = '9600';

    public function __construct(string $basePath, ?string $port = null, ?string $speed = null)
    {
        $this->initPaths($basePath);
        $this->applySerialSettings($port, $speed ?? self::DEFAULT_SPEED);
    }

    public function fiscal(array $items, array $payments): ReceiptResult
    {
        return $this->receipt('0', '8', $items, $payments);
    }

    /**
     * Storno (refund) receipt.
     *
     * Each storno sample in docs/accent/fp700/StorniSmetki is its twin in
     * FiskalniSmetki with exactly two bytes changed: the open command 48 ('0')
     * becomes 85 ('U') and the close command 56 ('8') becomes 86 ('V'). The
     * operator and till parameters, the item and payment lines and the
     * closing blank line stay as they are, so this is fiscal() with that
     * change and no other.
     *
     * The samples pay with a bare "5\t", which settles the whole total in
     * cash. The payment lines here state each payment's mode and amount, as
     * fiscal()'s do, so that a card refund is recorded as card.
     */
    public function storno(array $items, array $payments): ReceiptResult
    {
        return $this->receipt('U', 'V', $items, $payments);
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

    /**
     * Prints one receipt: open, items, payments, close.
     *
     * @param string $open  Command that opens it: '0' sale, 'U' storno.
     * @param string $close Command that closes it: '8' sale, 'V' storno.
     */
    private function receipt(string $open, string $close, array $items, array $payments): ReceiptResult
    {
        $commands = [
            $this->cmd($open, '1,0000,1'),
            ...array_map(fn(array $item)    => $this->cmd('1', $this->itemData($item)),    $items),
            ...array_map(fn(array $payment) => $this->cmd('5', $this->paymentData($payment)), $payments),
            $this->cmd($close),
        ];

        // Every command is CRLF-terminated and the batch ends with a blank line,
        // matching the vendor reference files in docs/accent/fp700 byte for byte
        // (e.g. "…%8\r\n\r\n"). Single-command operations carry no terminator.
        $this->execute(implode(self::CRLF, $commands) . self::CRLF . self::CRLF);

        // What the FP700 answers is documented nowhere in docs/accent. The
        // command reference there is the SY series' — tab-separated fields,
        // P/F verdicts — not the comma-separated dialect this model speaks,
        // and the FP700 samples are input only. A number read out of an answer
        // nobody has described would be a guess, so none is reported;
        // ecrprint.log records what the printer did send back.
        return ReceiptResult::unknown();
    }

    private function itemData(array $item): string
    {
        $name     = $this->itemName($item['name']);
        $vat      = $this->vatByte($item['vat'] ?? 'A');
        $price    = number_format((float)$item['price'],    2, '.', '');
        $quantity = number_format((float)($item['quantity'] ?? 1), 3, '.', '');
        $mkd      = ($item['mkd'] ?? false) ? self::MKD_ITEM : '';

        // ",-20.00" after the quantity is a 20% discount on the line; a
        // positive value would be a surcharge (Datecs protocol, command 49).
        $discount = $this->hasDiscount($item) ? ',-' . $this->formatDiscount($item) : '';

        return $name . self::TAB . $mkd . $vat . $price . '*' . $quantity . $discount;
    }

    private function paymentData(array $payment): string
    {
        // Money uses 2 decimals (vendor reference: "\tD15.00", "\tP5.00");
        // 3 decimals are only for item quantities.
        $mode   = ($payment['cash'] ?? true) ? 'P' : 'D';
        $amount = number_format((float)$payment['amount'], 2, '.', '');

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
