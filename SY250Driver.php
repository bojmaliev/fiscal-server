<?php

/**
 * SY250 (SY55 / SY46 / SY250) fiscal printer driver.
 *
 * Uses the same ecrprint.exe / file-based transport as FP700Driver.
 * All commands for one operation are batched into a single ecrprint.in file
 * and the exe is invoked once (identical approach to FP700).
 *
 * Command format written to ecrprint.in:
 *   {SeqByte}{CmdChar}[{Param1}\t{Param2}\t…{ParamN}\t]
 * Multiple commands are separated by \r\n.
 *
 * SeqByte cycles 33–255 then wraps to 32, persisted in seq.txt.
 * CmdChar is the ASCII character whose ordinal matches the SY250 command number:
 *   '0'=48 open receipt,  '1'=49 register item,  '5'=53 payment,
 *   '8'=56 close receipt, 'E'=69 Z/X-report,     'F'=70 deposit/withdraw,
 *   '^'=94 period report.
 */
class SY250Driver extends EcrPrintDriver
{
    private const CRLF = "\r\n";

    private string $opCode;
    private string $opPwd;

    public function __construct(string $basePath)
    {
        $this->initPaths($basePath);
        $this->opCode = '1';
        $this->opPwd  = '1';
    }

    public function fiscal(array $items, array $payments): void
    {
        $commands = [
            // Open fiscal receipt: opCode, opPwd, '', receiptType=0
            $this->cmd('0', $this->opCode, $this->opPwd, '', '0'),

            // Register each item
            ...array_map(fn(array $item) => $this->itemCmd($item), $items),

            // Process payments: '0'=cash, '1'=card, '2'=credit
            ...array_map(fn(array $payment) => $this->paymentCmd($payment), $payments),

            // Close fiscal receipt
            $this->cmd('8'),
        ];

        $this->execute(implode(self::CRLF, $commands));
    }

    public function closeDayReport(): void
    {
        // 'E' with 'Z' = Z-report (resets daily totals)
        $this->execute($this->cmd('E', 'Z'));
    }

    public function controlReport(): void
    {
        // 'E' with 'X' = X-report (read-only, no reset)
        $this->execute($this->cmd('E', 'X'));
    }

    public function depositWithdrawMoney(float $amount): void
    {
        // 'F': type '0'=cash-in, '1'=cash-out
        $type = $amount >= 0 ? '0' : '1';
        $this->execute($this->cmd('F', $type, number_format(abs($amount), 2, '.', '')));
    }

    public function periodShortReport(string $from, string $to): void
    {
        // '^': type '0'=short report; dates in DD-MM-YY format
        $this->execute($this->cmd('^', '0', $this->toSY250Date($from), $this->toSY250Date($to)));
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function itemCmd(array $item): string
    {
        return $this->cmd(
            '1',
            $this->win1251($item['name']),
            $this->taxCode($item['vat'] ?? 'A'),
            number_format((float) $item['price'],           2, '.', ''),
            number_format((float) ($item['quantity'] ?? 1), 3, '.', ''),
            ($item['mkd'] ?? false) ? '1' : '0',
            '',  // DiscountType  — no discount
            ''   // DiscountValue — no discount
        );
    }

    private function paymentCmd(array $payment): string
    {
        $mode   = ($payment['cash'] ?? true) ? '0' : '1';
        $amount = number_format((float) $payment['amount'], 2, '.', '');
        return $this->cmd('5', $mode, $amount);
    }

    /**
     * Maps the API VAT letter to the SY250 numeric tax code.
     * Documentation: "1-A, 2-Б, 3-В, 4-Г"
     */
    private function taxCode(string $vat): string
    {
        return match ($vat) {
            'A' => '1',
            'B' => '2',
            'V' => '3',
            'G' => '4',
            default => throw new \InvalidArgumentException("Invalid VAT code: $vat"),
        };
    }

    /** Converts YYYY-MM-DD to DD-MM-YY for SY250 date parameters. */
    private function toSY250Date(string $date): string
    {
        [$year, $month, $day] = explode('-', $date);
        return $day . '-' . $month . '-' . substr($year, -2);
    }

    /**
     * Builds one SY250 command: {SeqByte}{CmdChar}[{Param1}\t…{ParamN}\t]
     * Trailing \t is added only when params are present.
     */
    private function cmd(string $cmdChar, string ...$params): string
    {
        $data = $params ? implode("\t", $params) . "\t" : '';
        return chr($this->nextSeq()) . $cmdChar . $data;
    }
}
