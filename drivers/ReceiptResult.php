<?php

/**
 * What the vendor tooling reported about a receipt it has just printed — the
 * body q=fiscal answers with.
 *
 * Both fields are best-effort. By the time anyone looks, the paper is already
 * out, so an answer that cannot be read has to come back as "unknown" rather
 * than as a failure: a printed receipt reported as failed invites the operator
 * to print a duplicate. And each field is null unless it was read from output
 * documented to carry it. Nothing is inferred — a number that might be the
 * receipt's does more harm than none when it turns out not to be.
 */
final class ReceiptResult
{
    public function __construct(
        private ?string $receiptNumber = null,
        private ?string $fiscalMemorySerial = null
    ) {
    }

    /** For a printer whose tooling reports nothing that can be read with confidence. */
    public static function unknown(): self
    {
        return new self();
    }

    /**
     * Runs a parser over a receipt that has already printed. Whatever it
     * throws is swallowed and reported as unknown: this is the one place where
     * an unexpected error must not reach the caller as a failed request.
     *
     * @param callable(): self $parse
     */
    public static function bestEffort(callable $parse): self
    {
        try {
            return $parse();
        } catch (\Throwable) {
            return self::unknown();
        }
    }

    /** @return array{receipt_number: ?string, fiscal_memory_serial: ?string} */
    public function toArray(): array
    {
        return [
            'receipt_number'       => $this->receiptNumber,
            'fiscal_memory_serial' => $this->fiscalMemorySerial,
        ];
    }
}
