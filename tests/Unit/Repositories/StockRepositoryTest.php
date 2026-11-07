<?php

namespace Tests\Unit\Repositories;

use App\Features\Stock\Models\Stock;
use App\Features\Stock\Repositories\StockRepository;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private StockRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new StockRepository;
    }

    public function test_create_and_find(): void
    {
        $user = User::factory()->create();
        $stock = $this->repository->create([
            'user_id' => $user->id, 'name' => 'Pulpen', 'quantity' => 10, 'status' => 'available',
        ]);

        $this->assertTrue($this->repository->find($stock->id)->is($stock));
        $this->assertTrue($this->repository->findOrFail($stock->id)->is($stock));
        $this->assertNull($this->repository->find(9999));

        $this->expectException(ModelNotFoundException::class);
        $this->repository->findOrFail(9999);
    }

    public function test_update_and_delete(): void
    {
        $stock = Stock::factory()->create(['name' => 'Lama']);

        $this->assertTrue($this->repository->update($stock, ['name' => 'Baru']));
        $this->assertSame('Baru', $stock->fresh()->name);

        $this->assertTrue($this->repository->delete($stock));
        $this->assertDatabaseMissing('stocks', ['id' => $stock->id]);
    }

    public function test_for_user_only_returns_own_stocks_with_filters(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        Stock::factory()->create(['user_id' => $user->id, 'name' => 'Beras Merah', 'sku' => 'RICE-1', 'description' => 'Karbohidrat', 'status' => 'available']);
        Stock::factory()->create(['user_id' => $user->id, 'name' => 'Gula', 'sku' => 'SUG-1', 'description' => 'Pemanis', 'status' => 'low_stock']);
        Stock::factory()->create(['user_id' => $other->id, 'name' => 'Beras Milik Orang Lain']);

        $all = $this->repository->forUser($user->id);
        $this->assertCount(2, $all);

        $this->assertCount(1, $this->repository->forUser($user->id, 'low_stock'));
        $this->assertSame('Gula', $this->repository->forUser($user->id, 'low_stock')->first()->name);

        $this->assertSame('Beras Merah', $this->repository->forUser($user->id, null, 'Beras')->first()->name);
        $this->assertCount(1, $this->repository->forUser($user->id, null, 'SUG-1'));
        $this->assertCount(1, $this->repository->forUser($user->id, null, 'Pemanis'));
        $this->assertCount(0, $this->repository->forUser($user->id, 'out_of_stock', 'Beras'));
    }

    public function test_for_user_paginates(): void
    {
        $user = User::factory()->create();
        Stock::factory()->count(12)->create(['user_id' => $user->id]);

        $page = $this->repository->forUser($user->id, null, null, 5);

        $this->assertSame(12, $page->total());
        $this->assertCount(5, $page->items());
        $this->assertSame(3, $page->lastPage());
    }

    public function test_all_for_user_and_skus_for_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Stock::factory()->create(['user_id' => $user->id, 'sku' => 'A-1']);
        Stock::factory()->create(['user_id' => $user->id, 'sku' => null]);
        Stock::factory()->create(['user_id' => $other->id, 'sku' => 'Z-9']);

        $this->assertCount(2, $this->repository->allForUser($user->id));
        $this->assertSame(['A-1'], $this->repository->skusForUser($user->id));
    }

    public function test_insert_many(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($this->repository->insertMany([]));

        $now = now();
        $this->assertTrue($this->repository->insertMany([
            ['user_id' => $user->id, 'name' => 'A', 'quantity' => 1, 'unit' => 'pcs', 'min_stock' => 5, 'status' => 'low_stock', 'created_at' => $now, 'updated_at' => $now],
            ['user_id' => $user->id, 'name' => 'B', 'quantity' => 9, 'unit' => 'pcs', 'min_stock' => 5, 'status' => 'available', 'created_at' => $now, 'updated_at' => $now],
        ]));

        $this->assertSame(2, Stock::where('user_id', $user->id)->count());
    }
}
