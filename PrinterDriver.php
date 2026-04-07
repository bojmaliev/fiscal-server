<?php

interface PrinterDriver
{
    /**
     * Print a fiscal receipt with items and payments.
     *
     * @param array $items    Each: ['name'=>string, 'vat'=>'A'|'B'|'V'|'G', 'price'=>float, 'quantity'=>float, 'mkd'=>bool]
     * @param array $payments Each: ['amount'=>float, 'cash'=>bool]
     */
    public function fiscal(array $items, array $payments): void;

    /** Print Z-report and close the fiscal day. */
    public function closeDayReport(): void;

    /** Print X-report (non-resetting control report). */
    public function controlReport(): void;

    /**
     * Cash-in (positive amount) or cash-out (negative amount) operation.
     *
     * @param float $amount Positive = cash in, negative = cash out.
     */
    public function depositWithdrawMoney(float $amount): void;

    /**
     * Print a fiscal memory report for a date range.
     *
     * @param string $from Start date in YYYY-MM-DD format.
     * @param string $to   End date in YYYY-MM-DD format.
     */
    public function periodShortReport(string $from, string $to): void;
}
