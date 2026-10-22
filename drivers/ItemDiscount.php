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

    /** The unit price with the discount already taken off, to 2 decimals. */
    protected function discountedPrice(array $item): float
    {
        $price = (float) $item['price'];

        return round($price - $price * $this->discountPercent($item) / 100, 2);
    }

    /** The percentage as the printers expect it: 2 decimals, no sign. */
    protected function formatDiscount(array $item): string
    {
        return number_format($this->discountPercent($item), 2, '.', '');
    }
}
