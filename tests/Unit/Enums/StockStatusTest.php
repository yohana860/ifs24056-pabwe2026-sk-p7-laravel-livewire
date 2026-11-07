<?php

namespace Tests\Unit\Enums;

use App\Features\Stock\Enums\StockStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StockStatusTest extends TestCase
{
    public function test_stock_status_labels(): void
    {
        $this->assertSame('Tersedia', StockStatus::AVAILABLE->label());
        $this->assertSame('Stok Menipis', StockStatus::LOW_STOCK->label());
        $this->assertSame('Stok Habis', StockStatus::OUT_OF_STOCK->label());
    }

    public function test_stock_status_badge_classes(): void
    {
        $this->assertStringContainsString('emerald', StockStatus::AVAILABLE->badgeClass());
        $this->assertStringContainsString('amber', StockStatus::LOW_STOCK->badgeClass());
        $this->assertStringContainsString('red', StockStatus::OUT_OF_STOCK->badgeClass());
    }

    /**
     * @return array<string, array{int, int, StockStatus}>
     */
    public static function quantityProvider(): array
    {
        return [
            'zero quantity is out of stock' => [0, 5, StockStatus::OUT_OF_STOCK],
            'zero quantity with zero threshold' => [0, 0, StockStatus::OUT_OF_STOCK],
            'negative is treated as out of stock' => [-3, 5, StockStatus::OUT_OF_STOCK],
            'below threshold is low' => [3, 5, StockStatus::LOW_STOCK],
            'equal to threshold is low' => [5, 5, StockStatus::LOW_STOCK],
            'one above threshold is available' => [6, 5, StockStatus::AVAILABLE],
            'any positive with zero threshold is available' => [1, 0, StockStatus::AVAILABLE],
        ];
    }

    #[DataProvider('quantityProvider')]
    public function test_from_quantity(int $quantity, int $minStock, StockStatus $expected): void
    {
        $this->assertSame($expected, StockStatus::fromQuantity($quantity, $minStock));
    }
}
