<?php

/**
 * Severec fiscal printer driver (SY55 / SY46 / SY250 via Severec.exe).
 *
 * Uses a file-based transport: writes a command file then invokes Severec.exe
 * with the filename as an argument (unlike ecrprint.exe which reads a fixed file).
 *
 * Command format written to the input file:
 *   {SeqByte}{CmdChar}{Data}\r\n
 *
 * SeqByte cycles 33–255 then wraps to 32, persisted in severec_seq.txt.
 *
 * Key commands:
 *   '0'  open receipt:    {seq}0{opCode},{opPwd},{receiptType}
 *   '1'  register item:   {seq}1{name}\t{vat}{price}*{qty}
 *   '5'  payment:         {seq}5\t{mode}{amount}   (C=cash, D=card)
 *   '8'  close receipt:   {seq}8
 *   'E'  report:          {seq}E1 (Z-report) / {seq}E3 (X-report), followed by {seq}?
 *   'F'  cash movement:   {seq}F{signed-amount}, followed by {seq}5\t and {seq}8
 *   'O'  period report:   {seq}O{fromDDMMYY},{toDDMMYY}
 */
class SeverecDriver extends EcrPrintDriver
{
    use DunaResult;

    private const CRLF = "\r\n";

    private string $opCode;
    private string $opPwd;

    public function __construct(string $basePath, ?string $port = null, ?string $speed = null)
    {
        $severecDir       = $basePath . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'severec';
        $this->seqFile    = $severecDir . DIRECTORY_SEPARATOR . 'severec_seq.txt';
        $this->inputFile  = $severecDir . DIRECTORY_SEPARATOR . 'severec.in';
        $this->execPath   = $severecDir . DIRECTORY_SEPARATOR . 'Severec.exe';
        $this->configFile = $severecDir . DIRECTORY_SEPARATOR . 'FISKAL.INI';
        $this->initResultPaths($severecDir);
        $this->applySerialSettings($port, $speed);
        $this->opCode = '1';
        $this->opPwd  = '0001';
    }

    /**
     * Overrides the ecrprint.xml writer — Severec.exe is configured through an
     * INI file instead. Rewrites only the Port= / Speed= values and leaves
     * every other key and any comments untouched.
     *
     * @param string|null $speed Severec's SPEED is a vendor *code*, not a baud
     *                           rate (the shipped file uses 5), unlike the
     *                           literal baud ecrprint.xml expects.
     */
    protected function applySerialSettings(?string $port, ?string $speed): void
    {
        if ($port === null && $speed === null) {
            return;
        }

        $original = @file_get_contents($this->configFile);

        if ($original === false) {
            throw new \RuntimeException('Cannot read ' . $this->configFile);
        }

        $updated = $original;

        foreach (['Port' => $port, 'Speed' => $speed] as $key => $value) {
            if ($value === null) {
                continue;
            }

            $pattern = '/^(' . $key . '\s*=\s*).*$/mi';

            if (!preg_match($pattern, $updated)) {
                throw new \RuntimeException("{$key}= missing from " . $this->configFile);
            }

            $updated = preg_replace($pattern, '${1}' . $value, $updated, 1);
        }

        if ($updated !== $original && file_put_contents($this->configFile, $updated, LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write ' . $this->configFile);
        }
    }

    public function fiscal(array $items, array $payments): void
    {
        $commands = [
            // Open receipt: opCode,opPwd,receiptType (comma-separated)
            $this->cmd('0', "{$this->opCode},{$this->opPwd},1"),

            // Register each item
            ...array_map(fn(array $item) => $this->itemCmd($item), $items),

            // Payments
            ...array_map(fn(array $payment) => $this->paymentCmd($payment), $payments),

            // Close receipt
            $this->cmd('8'),
        ];

        $this->execute(implode(self::CRLF, $commands) . self::CRLF);
    }

    public function closeDayReport(): void
    {
        // E1 = Z-report (resets daily totals), followed by status query
        $this->execute(
            $this->cmd('E', '1') . self::CRLF .
            $this->cmd('?') . self::CRLF
        );
    }

    public function controlReport(): void
    {
        // E3 = X-report (read-only), followed by status query
        $this->execute(
            $this->cmd('E', '3') . self::CRLF .
            $this->cmd('?') . self::CRLF
        );
    }

    public function depositWithdrawMoney(float $amount): void
    {
        // Cash movement is its own mini-receipt: F{amount} + auto-payment + close
        $this->execute(
            $this->cmd('F', number_format($amount, 2, '.', '')) . self::CRLF .
            $this->cmd('5', "\t") . self::CRLF .
            $this->cmd('8') . self::CRLF
        );
    }

    public function periodShortReport(string $from, string $to): void
    {
        // O command with DDMMYY,DDMMYY
        $this->execute($this->cmd('O', $this->toSeverecDate($from) . ',' . $this->toSeverecDate($to)) . self::CRLF);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function itemCmd(array $item): string
    {
        $name  = $this->itemName($item['name']);
        $vat   = $this->vatCode($item['vat'] ?? 'A');
        $mkd   = ($item['mkd'] ?? false) ? '@' : '';
        $price = number_format((float) $item['price'],           2, '.', '');
        $qty   = number_format((float) ($item['quantity'] ?? 1), 3, '.', '');

        // Format: {name}\t{mkd}{vat}{price}*{qty}
        return $this->cmd('1', "{$name}\t{$mkd}{$vat}{$price}*{$qty}");
    }

    private function paymentCmd(array $payment): string
    {
        $mode   = ($payment['cash'] ?? true) ? 'C' : 'D';
        $amount = number_format((float) $payment['amount'], 2, '.', '');

        // Format: \t{mode}{amount}
        return $this->cmd('5', "\t{$mode}{$amount}");
    }

    /**
     * Maps API VAT letter to Severec VAT letter (A→A, B→B, V→C, G→D).
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

    /** Converts YYYY-MM-DD to DDMMYY for Severec date parameters. */
    private function toSeverecDate(string $date): string
    {
        [$year, $month, $day] = explode('-', $date);
        return $day . $month . substr($year, -2);
    }

    /** Builds one Severec command: {SeqByte}{CmdChar}[{data}] */
    private function cmd(string $cmdChar, string $data = ''): string
    {
        return chr($this->nextSeq()) . $cmdChar . $data;
    }

    /**
     * Overrides EcrPrintDriver::execute() because Severec.exe requires the
     * input filename as a command-line argument, unlike ecrprint.exe.
     *
     * Runs via runProcess() for the argument escaping and exit-code check.
     * Severec.exe locates FISKAL.INI, Result.out and Log\Error next to itself
     * (AppDomain.BaseDirectory), so unlike ecrprint.exe it does not depend on
     * the working directory.
     */
    protected function execute(string $content): void
    {
        $this->clearFiles($this->resultFile);
        $logBefore = $this->snapshotFiles($this->errorLogDir);

        file_put_contents($this->inputFile, $content, LOCK_EX);

        $this->runProcess($this->execPath, [$this->inputFile]);
        $this->assertResultOk($logBefore);
    }
}
