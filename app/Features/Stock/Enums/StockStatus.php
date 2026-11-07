<?php

namespace App\Features\Stock\Enums;

enum StockStatus: string
{
    case AVAILABLE = 'available';
    case LOW_STOCK = 'low_stock';
    case OUT_OF_STOCK = 'out_of_stock';

    /**
     * Resolve the status from the current quantity and the minimum stock threshold.
     *
     * - quantity <= 0            => out_of_stock
     * - quantity <= min_stock    => low_stock
     * - otherwise                => available
     */
    public static function fromQuantity(int $quantity, int $minStock): self
    {
        if ($quantity <= 0) {
            return self::OUT_OF_STOCK;
        }

        if ($quantity <= $minStock) {
            return self::LOW_STOCK;
        }

        return self::AVAILABLE;
    }

    public function label(): string
    {
        return match ($this) {
            self::AVAILABLE => 'Tersedia',
            self::LOW_STOCK => 'Stok Menipis',
            self::OUT_OF_STOCK => 'Stok Habis',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::AVAILABLE => 'bg-emerald-100 text-emerald-800 border-emerald-300',
            self::LOW_STOCK => 'bg-amber-100 text-amber-800 border-amber-300',
            self::OUT_OF_STOCK => 'bg-red-100 text-red-800 border-red-300',
        };
    }
}
