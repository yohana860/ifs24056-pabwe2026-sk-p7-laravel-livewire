<?php

namespace Tests\Unit\DTO;

use App\Features\Ai\DTO\GeneratedStockItem;
use PHPUnit\Framework\TestCase;

class GeneratedStockItemTest extends TestCase
{
    public function test_dto_properties_and_to_array(): void
    {
        $dto = new GeneratedStockItem(
            name: 'Kertas A4',
            description: 'Kertas 80gsm',
            quantity: 20,
            unit: 'rim',
            price: 55000.0,
            minStock: 4,
        );

        $this->assertSame('Kertas A4', $dto->name);
        $this->assertSame([
            'name' => 'Kertas A4',
            'description' => 'Kertas 80gsm',
            'quantity' => 20,
            'unit' => 'rim',
            'price' => 55000.0,
            'min_stock' => 4,
        ], $dto->toArray());
    }

    public function test_dto_defaults(): void
    {
        $dto = new GeneratedStockItem(name: 'Item');

        $this->assertNull($dto->description);
        $this->assertSame(0, $dto->quantity);
        $this->assertSame('pcs', $dto->unit);
        $this->assertNull($dto->price);
        $this->assertSame(5, $dto->minStock);
    }
}
