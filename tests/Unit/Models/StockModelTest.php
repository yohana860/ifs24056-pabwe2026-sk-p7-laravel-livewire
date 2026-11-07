<?php

namespace Tests\Unit\Models;

use App\Features\Stock\Enums\StockStatus;
use App\Features\Stock\Models\Stock;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StockModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_belongs_to_user_and_user_has_stocks(): void
    {
        $user = User::factory()->create();
        $stock = Stock::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($stock->user->is($user));
        $this->assertTrue($user->stocks->first()->is($stock));
    }

    public function test_stock_casts(): void
    {
        $stock = Stock::factory()->create([
            'quantity' => '12',
            'min_stock' => '3',
            'price' => 12500.5,
        ]);
        $stock->refresh();

        $this->assertSame(12, $stock->quantity);
        $this->assertSame(3, $stock->min_stock);
        $this->assertSame('12500.50', $stock->price);
        $this->assertInstanceOf(StockStatus::class, $stock->status);
    }

    public function test_stock_factory_states(): void
    {
        $low = Stock::factory()->lowStock()->create();
        $out = Stock::factory()->outOfStock()->create();
        $available = Stock::factory()->create();

        $this->assertSame(StockStatus::LOW_STOCK, $low->status);
        $this->assertSame(StockStatus::OUT_OF_STOCK, $out->status);
        $this->assertSame(0, $out->quantity);
        $this->assertSame(StockStatus::AVAILABLE, $available->status);
    }

    public function test_cover_image_url(): void
    {
        Storage::fake('public');
        $stock = Stock::factory()->create(['cover_image_path' => null]);
        $this->assertNull($stock->coverImageUrl());

        $stock->cover_image_path = 'stock-covers/missing.jpg';
        $this->assertNull($stock->coverImageUrl());

        Storage::disk('public')->put('stock-covers/real.jpg', 'x');
        $stock->cover_image_path = 'stock-covers/real.jpg';
        $this->assertStringContainsString('stock-covers/real.jpg', $stock->coverImageUrl());
    }

    public function test_mass_assignment_protects_id_and_timestamps(): void
    {
        $user = User::factory()->create();
        $stock = new Stock(['id' => 999, 'user_id' => $user->id, 'name' => 'X', 'created_at' => '2000-01-01']);

        $this->assertNull($stock->id);
        $this->assertNull($stock->created_at);
        $this->assertSame('X', $stock->name);
    }

    public function test_sku_is_unique_per_user_but_reusable_across_users(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();

        Stock::factory()->create(['user_id' => $a->id, 'sku' => 'DUP-1']);
        Stock::factory()->create(['user_id' => $b->id, 'sku' => 'DUP-1']);
        Stock::factory()->create(['user_id' => $a->id, 'sku' => null]);
        Stock::factory()->create(['user_id' => $a->id, 'sku' => null]);

        $this->assertSame(4, Stock::count());

        $this->expectException(QueryException::class);
        Stock::factory()->create(['user_id' => $a->id, 'sku' => 'DUP-1']);
    }

    public function test_deleting_user_cascades_to_stocks(): void
    {
        $user = User::factory()->create();
        Stock::factory()->count(2)->create(['user_id' => $user->id]);

        $user->delete();

        $this->assertSame(0, Stock::count());
    }
}
