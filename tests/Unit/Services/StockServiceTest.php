<?php

namespace Tests\Unit\Services;

use App\Features\Stock\Enums\StockStatus;
use App\Features\Stock\Models\Stock;
use App\Features\Stock\Repositories\StockRepository;
use App\Features\Stock\Services\StockService;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StockServiceTest extends TestCase
{
    use RefreshDatabase;

    private StockService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new StockService(new StockRepository);
    }

    public function test_create_stock_derives_status(): void
    {
        $user = User::factory()->create();

        $available = $this->service->createStock($user->id, ['name' => 'A', 'quantity' => 20, 'min_stock' => 5]);
        $low = $this->service->createStock($user->id, ['name' => 'B', 'quantity' => 5, 'min_stock' => 5]);
        $out = $this->service->createStock($user->id, ['name' => 'C', 'quantity' => 0]);

        $this->assertSame(StockStatus::AVAILABLE, $available->status);
        $this->assertSame(StockStatus::LOW_STOCK, $low->status);
        $this->assertSame(StockStatus::OUT_OF_STOCK, $out->status);
        $this->assertSame('pcs', $out->unit);
        $this->assertSame(5, $out->min_stock);
        $this->assertNull($out->sku);
    }

    public function test_create_ignores_client_supplied_status(): void
    {
        $user = User::factory()->create();

        $stock = $this->service->createStock($user->id, ['name' => 'A', 'quantity' => 0, 'status' => 'available']);

        $this->assertSame(StockStatus::OUT_OF_STOCK, $stock->status);
    }

    public function test_update_stock_keeps_status_in_sync(): void
    {
        $stock = Stock::factory()->create(['quantity' => 50, 'min_stock' => 5]);

        $this->service->updateStock($stock, [
            'name' => 'Renamed', 'sku' => 'NEW-1', 'description' => 'd', 'quantity' => 2,
            'unit' => 'box', 'price' => 1000, 'min_stock' => 10, 'cover_image_path' => 'x.jpg',
        ]);

        $stock->refresh();
        $this->assertSame('Renamed', $stock->name);
        $this->assertSame('NEW-1', $stock->sku);
        $this->assertSame('box', $stock->unit);
        $this->assertSame(StockStatus::LOW_STOCK, $stock->status);
        $this->assertSame('x.jpg', $stock->cover_image_path);
    }

    public function test_update_stock_partial_data_preserves_existing_values(): void
    {
        $stock = Stock::factory()->create(['name' => 'Keep', 'sku' => 'K-1', 'quantity' => 10, 'min_stock' => 3, 'unit' => 'kg']);

        $this->service->updateStock($stock, ['min_stock' => 20]);

        $stock->refresh();
        $this->assertSame('Keep', $stock->name);
        $this->assertSame('K-1', $stock->sku);
        $this->assertSame(10, $stock->quantity);
        $this->assertSame('kg', $stock->unit);
        $this->assertSame(StockStatus::LOW_STOCK, $stock->status);
    }

    public function test_adjust_quantity_increase_and_decrease_sync_status(): void
    {
        $stock = Stock::factory()->create(['quantity' => 6, 'min_stock' => 5, 'status' => 'available']);

        $this->service->adjustQuantity($stock, -1);
        $stock->refresh();
        $this->assertSame(5, $stock->quantity);
        $this->assertSame(StockStatus::LOW_STOCK, $stock->status);

        $this->service->adjustQuantity($stock, -5);
        $stock->refresh();
        $this->assertSame(0, $stock->quantity);
        $this->assertSame(StockStatus::OUT_OF_STOCK, $stock->status);

        $this->service->adjustQuantity($stock, 10);
        $stock->refresh();
        $this->assertSame(10, $stock->quantity);
        $this->assertSame(StockStatus::AVAILABLE, $stock->status);
    }

    public function test_adjust_quantity_never_goes_negative(): void
    {
        $stock = Stock::factory()->create(['quantity' => 2, 'min_stock' => 5]);

        try {
            $this->service->adjustQuantity($stock, -3);
            $this->fail('DomainException expected');
        } catch (DomainException $e) {
            $this->assertSame('Quantity tidak boleh kurang dari 0.', $e->getMessage());
        }

        $this->assertSame(2, $stock->fresh()->quantity);
    }

    public function test_find_and_list(): void
    {
        $stock = Stock::factory()->create();

        $this->assertTrue($this->service->findStock($stock->id)->is($stock));
        $this->assertSame(1, $this->service->getStocksForUser($stock->user_id)->total());
    }

    public function test_cover_upload_replace_and_delete(): void
    {
        Storage::fake('public');
        $stock = Stock::factory()->create();

        $path = $this->service->updateCoverPhoto($stock, UploadedFile::fake()->image('a.jpg'));
        Storage::disk('public')->assertExists($path);

        $newPath = $this->service->updateCoverPhoto($stock->fresh(), UploadedFile::fake()->image('b.png'));
        Storage::disk('public')->assertMissing($path);
        Storage::disk('public')->assertExists($newPath);

        $this->service->deleteCoverPhoto($stock->fresh());
        Storage::disk('public')->assertMissing($newPath);
        $this->assertNull($stock->fresh()->cover_image_path);

        // deleting a cover when none exists is harmless
        $this->assertTrue($this->service->deleteCoverPhoto($stock->fresh()));
    }

    public function test_delete_stock_removes_cover_file(): void
    {
        Storage::fake('public');
        $stock = Stock::factory()->create();
        $path = $this->service->updateCoverPhoto($stock, UploadedFile::fake()->image('a.jpg'));

        $this->assertTrue($this->service->deleteStock($stock->fresh()));

        Storage::disk('public')->assertMissing($path);
        $this->assertDatabaseMissing('stocks', ['id' => $stock->id]);
    }
}
