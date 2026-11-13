<?php

/**
 * Reads the optional percentage discount on a receipt item.
 *
 * The Accent printers take the percentage on the sale line itself and print
 * it as its own line under the item. The Duna printers have no documented
 * discount field, so for them the discount is folded into the unit price
 * instead: the receipt shows the reduced price, and the total still matches
 * what the till charged.
 */
trait ItemDiscount
{
    /** The discount on this item in percent, 0 when there is none. */
    protected function discountPercent(array $item): float
    {
        return (float) ($item['discount'] ?? 0);
    }

    protected function hasDiscount(array $item): bool
    {
        return $this->discountPercent($item) > 0;
    }

    /**
     * The unit price with the discount already taken off, to 2 decimals,
     * rounded half up on its decimal value.
     *
     * The subtraction can land a hair under an exact half cent, and since PHP
     * 8.4 round() rounds the float as it is. 2817.95 less 10% is 2536.155,
     * which came out as 2536.15, a cent less than the till charged. Rounding
     * to 6 places first gives round() the decimal value again. That is what
     * PHP 8.3 and earlier did, and what pos_app's Dart engine does.
     */
    protected function discountedPrice(array $item): float
    {
        $price = (float) $item['price'];

        return round(round($price - $price * $this->discountPercent($item) / 100, 6), 2);
    }

    /** The percentage as the printers expect it: 2 decimals, no sign. */
    protected function formatDiscount(array $item): string
    {
        return number_format($this->discountPercent($item), 2, '.', '');
    }
}
