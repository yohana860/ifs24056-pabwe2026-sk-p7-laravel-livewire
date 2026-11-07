<?php

namespace Tests\Feature\Stock;

use App\Features\Stock\Enums\StockStatus;
use App\Features\Stock\Livewire\StockDetail;
use App\Features\Stock\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class StockDetailLivewireTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private Stock $stock;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->member = User::factory()->member()->create();
        $this->stock = Stock::factory()->create([
            'user_id' => $this->member->id, 'name' => 'Detail Barang', 'sku' => 'DT-1',
            'description' => '# Judul Markdown', 'quantity' => 10, 'min_stock' => 5, 'price' => 2500,
        ]);
    }

    public function test_member_can_view_own_stock_detail(): void
    {
        $this->actingAs($this->member)
            ->get(route('stocks.show', $this->stock))
            ->assertOk()
            ->assertSeeLivewire(StockDetail::class)
            ->assertSee('Detail Barang')
            ->assertSee('DT-1')
            ->assertSee('toastui-editor');

        Livewire::actingAs($this->member)
            ->test(StockDetail::class, ['stock' => $this->stock])
            ->assertSee('Detail Barang')
            ->assertSee('Rp 2.500')
            ->assertSee('# Judul Markdown', false);
    }

    public function test_detail_without_price_or_description(): void
    {
        $stock = Stock::factory()->create(['user_id' => $this->member->id, 'price' => null, 'description' => null, 'sku' => null]);

        Livewire::actingAs($this->member)
            ->test(StockDetail::class, ['stock' => $stock])
            ->assertSee('Harga belum diisi')
            ->assertSet('price', null)
            ->assertSet('sku', null);
    }

    public function test_member_cannot_view_other_members_stock(): void
    {
        $other = User::factory()->member()->create();

        $this->actingAs($other)->get(route('stocks.show', $this->stock))->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('stocks.show', $this->stock))->assertRedirect(route('login'));
    }

    public function test_open_and_close_edit_modal(): void
    {
        Livewire::actingAs($this->member)
            ->test(StockDetail::class, ['stock' => $this->stock])
            ->set('name', 'Dirty')
            ->call('openEditModal')
            ->assertSet('showEditModal', true)
            ->assertSet('name', 'Detail Barang')
            ->call('closeEditModal')
            ->assertSet('showEditModal', false);
    }

    public function test_update_stock_validation(): void
    {
        Stock::factory()->create(['user_id' => $this->member->id, 'sku' => 'TAKEN']);

        Livewire::actingAs($this->member)
            ->test(StockDetail::class, ['stock' => $this->stock])
            ->set('name', 'ab')
            ->set('quantity', -2)
            ->set('minStock', 'x')
            ->set('unit', '')
            ->set('price', -1)
            ->set('sku', 'TAKEN')
            ->call('saveStock')
            ->assertHasErrors(['name', 'quantity', 'minStock', 'unit', 'price', 'sku']);
    }

    public function test_update_stock_success_syncs_status(): void
    {
        Livewire::actingAs($this->member)
            ->test(StockDetail::class, ['stock' => $this->stock])
            ->call('openEditModal')
            ->set('name', 'Nama Baru')
            ->set('sku', '')
            ->set('price', '')
            ->set('quantity', 2)
            ->set('minStock', 5)
            ->call('saveStock')
            ->assertHasNoErrors()
            ->assertSet('showEditModal', false)
            ->assertSet('notice', ['type' => 'success', 'message' => 'Informasi stok berhasil disimpan.']);

        $this->stock->refresh();
        $this->assertSame('Nama Baru', $this->stock->name);
        $this->assertNull($this->stock->sku);
        $this->assertNull($this->stock->price);
        $this->assertSame(StockStatus::LOW_STOCK, $this->stock->status);
    }

    public function test_keeping_own_sku_is_not_a_duplicate(): void
    {
        Livewire::actingAs($this->member)
            ->test(StockDetail::class, ['stock' => $this->stock])
            ->call('saveStock')
            ->assertHasNoErrors();
    }

    public function test_adjust_quantity_buttons_and_negative_guard(): void
    {
        $component = Livewire::actingAs($this->member)
            ->test(StockDetail::class, ['stock' => $this->stock])
            ->call('adjustQuantity', -5)
            ->assertSet('quantity', 5);

        $this->assertSame(StockStatus::LOW_STOCK, $this->stock->fresh()->status);

        $component->call('adjustQuantity', -5)->assertSet('quantity', 0);
        $this->assertSame(StockStatus::OUT_OF_STOCK, $this->stock->fresh()->status);

        $component->call('adjustQuantity', -1)
            ->assertSet('quantity', 0)
            ->assertSet('notice', ['type' => 'error', 'message' => 'Quantity tidak boleh kurang dari 0.']);

        $component->call('adjustQuantity', 20);
        $this->assertSame(StockStatus::AVAILABLE, $this->stock->fresh()->status);
    }

    public function test_cover_upload_replace_and_delete(): void
    {
        $component = Livewire::actingAs($this->member)->test(StockDetail::class, ['stock' => $this->stock]);

        $component->set('coverPhoto', UploadedFile::fake()->image('cover.jpg', 200, 200))
            ->assertHasNoErrors()
            ->assertSet('coverPhoto', null);

        $first = $this->stock->fresh()->cover_image_path;
        $this->assertNotNull($first);
        Storage::disk('public')->assertExists($first);

        $component->set('coverPhoto', UploadedFile::fake()->image('cover2.png', 100, 100));
        $second = $this->stock->fresh()->cover_image_path;
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);

        $component->assertSee('Cover')
            ->call('confirmDeleteCover')->assertSet('confirmingCoverDeletion', true)
            ->call('cancelDeleteCover')->assertSet('confirmingCoverDeletion', false)
            ->call('confirmDeleteCover')
            ->call('deleteCoverPhoto')
            ->assertSet('confirmingCoverDeletion', false);

        Storage::disk('public')->assertMissing($second);
        $this->assertNull($this->stock->fresh()->cover_image_path);
    }

    public function test_cover_upload_rejects_invalid_files(): void
    {
        $component = Livewire::actingAs($this->member)->test(StockDetail::class, ['stock' => $this->stock]);

        $component->set('coverPhoto', UploadedFile::fake()->create('script.php', 10, 'application/x-php'))
            ->assertHasErrors(['coverPhoto']);
        $component->set('coverPhoto', UploadedFile::fake()->createWithContent('vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'))
            ->assertHasErrors(['coverPhoto']);
        $component->set('coverPhoto', UploadedFile::fake()->image('huge.jpg')->size(6000))
            ->assertHasErrors(['coverPhoto']);
        $component->set('coverPhoto', UploadedFile::fake()->create('fake.jpg', 10, 'text/plain'))
            ->assertHasErrors(['coverPhoto']);

        $this->assertNull($this->stock->fresh()->cover_image_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_delete_stock_removes_cover_and_redirects(): void
    {
        $this->stock->update(['cover_image_path' => Storage::disk('public')->putFile('stock-covers', UploadedFile::fake()->image('c.jpg'))]);
        $path = $this->stock->cover_image_path;

        Livewire::actingAs($this->member)
            ->test(StockDetail::class, ['stock' => $this->stock])
            ->call('confirmDeleteStock')->assertSet('confirmingStockDeletion', true)
            ->call('cancelDeleteStock')->assertSet('confirmingStockDeletion', false)
            ->call('deleteStock')
            ->assertRedirect(route('stocks.index'));

        $this->assertDatabaseMissing('stocks', ['id' => $this->stock->id]);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_admin_can_view_but_not_modify_member_stock(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('stocks.show', $this->stock))
            ->assertOk()
            ->assertSee('Detail Barang')
            ->assertDontSee('detail-increase')
            ->assertDontSee('detail-decrease');

        $fresh = fn () => Livewire::actingAs($admin)->test(StockDetail::class, ['stock' => $this->stock]);

        $fresh()->call('adjustQuantity', 1)->assertForbidden();
        $fresh()->call('openEditModal')->assertForbidden();
        $fresh()->call('saveStock')->assertForbidden();
        $fresh()->call('deleteStock')->assertForbidden();
        $fresh()->call('deleteCoverPhoto')->assertForbidden();
        $fresh()->set('coverPhoto', UploadedFile::fake()->image('x.jpg'))->assertForbidden();

        $stock = $this->stock->fresh();
        $this->assertSame(10, $stock->quantity);
        $this->assertSame('Detail Barang', $stock->name);
        $this->assertNull($stock->cover_image_path);
    }

    public function test_member_cannot_mount_component_for_foreign_stock(): void
    {
        $other = User::factory()->member()->create();

        Livewire::actingAs($other)
            ->test(StockDetail::class, ['stock' => $this->stock])
            ->assertForbidden();
    }
}
