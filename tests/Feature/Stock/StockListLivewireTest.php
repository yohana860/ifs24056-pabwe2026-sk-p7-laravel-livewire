<?php

namespace Tests\Feature\Stock;

use App\Features\Ai\Contracts\AiClientInterface;
use App\Features\Ai\DTO\GeneratedStockItem;
use App\Features\Stock\Enums\StockStatus;
use App\Features\Stock\Livewire\StockList;
use App\Features\Stock\Models\Stock;
use App\Features\Stock\Services\StockExcelService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class StockListLivewireTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->member = User::factory()->member()->create();
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function xlsxUpload(array $rows, string $name = 'stok.xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'up');
        (new Xlsx($spreadsheet))->save($path);
        $content = file_get_contents($path);
        unlink($path);

        return UploadedFile::fake()->createWithContent($name, $content);
    }

    public function test_guest_cannot_access_stocks(): void
    {
        $this->get('/stocks')->assertRedirect(route('login'));
    }

    public function test_member_can_view_own_stocks_only(): void
    {
        Stock::factory()->create(['user_id' => $this->member->id, 'name' => 'Barang Milik Member']);
        Stock::factory()->create(['name' => 'Barang Rahasia Orang Lain']);

        $this->actingAs($this->member);

        Livewire::test(StockList::class)
            ->assertSee('Barang Milik Member')
            ->assertDontSee('Barang Rahasia Orang Lain');
    }

    public function test_create_stock_validation(): void
    {
        $this->actingAs($this->member);
        Stock::factory()->create(['user_id' => $this->member->id, 'sku' => 'USED-1']);

        Livewire::test(StockList::class)
            ->call('openCreateModal')
            ->set('formName', '')
            ->set('formQuantity', -1)
            ->set('formMinStock', 'abc')
            ->set('formUnit', '')
            ->set('formPrice', -5)
            ->set('formSku', 'USED-1')
            ->call('saveStock')
            ->assertHasErrors(['formName', 'formQuantity', 'formMinStock', 'formUnit', 'formPrice', 'formSku']);

        $this->assertSame(1, Stock::count());
    }

    public function test_create_stock_success_derives_status(): void
    {
        $this->actingAs($this->member);

        Livewire::test(StockList::class)
            ->call('openCreateModal')
            ->assertSet('showFormModal', true)
            ->set('formName', 'Beras 5kg')
            ->set('formSku', '')
            ->set('formDescription', '**Premium**')
            ->set('formQuantity', 4)
            ->set('formUnit', 'karung')
            ->set('formPrice', '')
            ->set('formMinStock', 5)
            ->call('saveStock')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false);

        $stock = Stock::where('name', 'Beras 5kg')->firstOrFail();
        $this->assertSame($this->member->id, $stock->user_id);
        $this->assertNull($stock->sku);
        $this->assertNull($stock->price);
        $this->assertSame(StockStatus::LOW_STOCK, $stock->status);
    }

    public function test_same_sku_allowed_for_different_users(): void
    {
        Stock::factory()->create(['sku' => 'SHARED-1']);
        $this->actingAs($this->member);

        Livewire::test(StockList::class)
            ->call('openCreateModal')
            ->set('formName', 'Item')
            ->set('formSku', 'SHARED-1')
            ->call('saveStock')
            ->assertHasNoErrors();

        $this->assertSame(2, Stock::where('sku', 'SHARED-1')->count());
    }

    public function test_edit_stock_success_and_status_resync(): void
    {
        $stock = Stock::factory()->create(['user_id' => $this->member->id, 'name' => 'Lama', 'sku' => 'S-1', 'quantity' => 50, 'price' => 1500, 'min_stock' => 5]);
        $this->actingAs($this->member);

        Livewire::test(StockList::class)
            ->call('openEditModal', $stock->id)
            ->assertSet('editingStockId', $stock->id)
            ->assertSet('formName', 'Lama')
            ->assertSet('formSku', 'S-1')
            ->assertSet('showFormModal', true)
            ->set('formName', 'Baru')
            ->set('formQuantity', 0)
            ->call('saveStock')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false);

        $stock->refresh();
        $this->assertSame('Baru', $stock->name);
        $this->assertSame('S-1', $stock->sku); // own sku is not a duplicate of itself
        $this->assertSame(StockStatus::OUT_OF_STOCK, $stock->status);
    }

    public function test_edit_without_price_or_sku_loads_null_values(): void
    {
        $stock = Stock::factory()->create(['user_id' => $this->member->id, 'sku' => null, 'price' => null]);
        $this->actingAs($this->member);

        Livewire::test(StockList::class)
            ->call('openEditModal', $stock->id)
            ->assertSet('formSku', null)
            ->assertSet('formPrice', null);
    }

    public function test_reset_form_and_close_modal(): void
    {
        $this->actingAs($this->member);

        Livewire::test(StockList::class)
            ->set('formName', 'x')->set('formQuantity', 9)->set('formUnit', 'kg')->set('formMinStock', 1)
            ->call('resetForm')
            ->assertSet('formName', '')
            ->assertSet('formQuantity', 0)
            ->assertSet('formUnit', 'pcs')
            ->assertSet('formMinStock', 5)
            ->set('showFormModal', true)
            ->call('closeFormModal')
            ->assertSet('showFormModal', false);
    }

    public function test_member_cannot_edit_adjust_or_delete_other_members_stock(): void
    {
        $foreign = Stock::factory()->create(['quantity' => 10, 'name' => 'Foreign']);
        $this->actingAs($this->member);

        Livewire::test(StockList::class)->call('openEditModal', $foreign->id)->assertForbidden();
        Livewire::test(StockList::class)->call('adjustQuantity', $foreign->id, 1)->assertForbidden();
        Livewire::test(StockList::class)->call('deleteStock', $foreign->id)->assertForbidden();

        $foreign->refresh();
        $this->assertSame(10, $foreign->quantity);
        $this->assertSame('Foreign', $foreign->name);
    }

    public function test_save_cannot_edit_foreign_stock_via_tampered_id(): void
    {
        $foreign = Stock::factory()->create(['name' => 'Foreign']);
        $this->actingAs($this->member);

        Livewire::test(StockList::class)
            ->set('editingStockId', $foreign->id)
            ->set('formName', 'Hacked')
            ->call('saveStock')
            ->assertForbidden();

        $this->assertSame('Foreign', $foreign->fresh()->name);
    }

    public function test_admin_cannot_modify_member_stock_from_list_component(): void
    {
        $admin = User::factory()->admin()->create();
        $stock = Stock::factory()->create(['quantity' => 10]);
        $this->actingAs($admin);

        Livewire::test(StockList::class)->call('adjustQuantity', $stock->id, 5)->assertForbidden();

        $this->assertSame(10, $stock->fresh()->quantity);
    }

    public function test_adjust_quantity_increase_and_decrease(): void
    {
        $stock = Stock::factory()->create(['user_id' => $this->member->id, 'quantity' => 6, 'min_stock' => 5, 'status' => 'available']);
        $this->actingAs($this->member);

        $component = Livewire::test(StockList::class)
            ->call('adjustQuantity', $stock->id, -1);
        $this->assertSame(5, $stock->fresh()->quantity);
        $this->assertSame(StockStatus::LOW_STOCK, $stock->fresh()->status);

        $component->call('adjustQuantity', $stock->id, 10);
        $this->assertSame(15, $stock->fresh()->quantity);
        $this->assertSame(StockStatus::AVAILABLE, $stock->fresh()->status);
    }

    public function test_adjust_quantity_cannot_go_negative(): void
    {
        $stock = Stock::factory()->create(['user_id' => $this->member->id, 'quantity' => 0, 'min_stock' => 5, 'status' => 'out_of_stock']);
        $this->actingAs($this->member);

        Livewire::test(StockList::class)
            ->call('adjustQuantity', $stock->id, -1)
            ->assertSet('notice', ['type' => 'error', 'message' => 'Quantity tidak boleh kurang dari 0.'])
            ->assertSee('Quantity tidak boleh kurang dari 0.');

        $this->assertSame(0, $stock->fresh()->quantity);
    }

    public function test_delete_stock_with_confirmation(): void
    {
        $stock = Stock::factory()->create(['user_id' => $this->member->id]);
        $this->actingAs($this->member);

        Livewire::test(StockList::class)
            ->call('confirmDelete', $stock->id)
            ->assertSet('confirmingStockDeletionId', $stock->id)
            ->call('cancelDelete')
            ->assertSet('confirmingStockDeletionId', null)
            ->call('confirmDelete', $stock->id)
            ->call('deleteStock')
            ->assertSet('confirmingStockDeletionId', null);

        $this->assertDatabaseMissing('stocks', ['id' => $stock->id]);
    }

    public function test_delete_stock_by_explicit_id_and_noop_without_target(): void
    {
        $stock = Stock::factory()->create(['user_id' => $this->member->id]);
        $this->actingAs($this->member);

        Livewire::test(StockList::class)
            ->call('deleteStock')                // nothing selected -> no-op
            ->assertHasNoErrors();
        $this->assertDatabaseHas('stocks', ['id' => $stock->id]);

        Livewire::test(StockList::class)->call('deleteStock', $stock->id);
        $this->assertDatabaseMissing('stocks', ['id' => $stock->id]);
    }

    public function test_search_status_filter_and_pagination(): void
    {
        $this->actingAs($this->member);
        Stock::factory()->create(['user_id' => $this->member->id, 'name' => 'Alpha Item', 'sku' => 'AL-1', 'status' => 'available']);
        Stock::factory()->create(['user_id' => $this->member->id, 'name' => 'Beta Item', 'sku' => 'BE-1', 'status' => 'low_stock', 'quantity' => 2]);
        Stock::factory()->create(['user_id' => $this->member->id, 'name' => 'Gamma Item', 'sku' => 'GA-1', 'status' => 'out_of_stock', 'quantity' => 0]);

        Livewire::test(StockList::class)
            ->set('search', 'Alpha')
            ->assertSee('Alpha Item')->assertDontSee('Beta Item')
            ->set('search', 'BE-1')
            ->assertSee('Beta Item')->assertDontSee('Alpha Item')
            ->set('search', '')
            ->set('statusFilter', 'low_stock')
            ->assertSee('Beta Item')->assertDontSee('Alpha Item')->assertDontSee('Gamma Item')
            ->set('statusFilter', 'out_of_stock')
            ->assertSee('Gamma Item')->assertDontSee('Beta Item');

        Livewire::test(StockList::class)
            ->set('search', 'ZZZ-none')
            ->assertSee('Belum ada stok ditemukan');
    }

    public function test_pagination_shows_eight_per_page(): void
    {
        $this->actingAs($this->member);
        foreach (range(1, 10) as $i) {
            Stock::factory()->create(['user_id' => $this->member->id, 'name' => sprintf('Paged Item %02d', $i)]);
        }

        Livewire::test(StockList::class)
            ->assertSee('Paged Item 10')->assertSee('Paged Item 03')
            ->assertDontSee('Paged Item 02')
            ->call('gotoPage', 2)
            ->assertSee('Paged Item 02')->assertDontSee('Paged Item 10');
    }

    public function test_export_excel_only_contains_own_stocks(): void
    {
        Stock::factory()->create(['user_id' => $this->member->id]);
        $this->actingAs($this->member);

        Livewire::test(StockList::class)
            ->call('exportExcel')
            ->assertFileDownloaded();
    }

    public function test_import_excel_success_and_row_errors(): void
    {
        $this->actingAs($this->member);

        Livewire::test(StockList::class)
            ->call('openImportModal')
            ->assertSet('showImportModal', true)
            ->set('importFile', $this->xlsxUpload([
                StockExcelService::HEADERS,
                [1, 'Imported Item', 'IMP-1', '', 8, 'pcs', 1000, 5, '', ''],
            ]))
            ->call('importExcel')
            ->assertHasNoErrors()
            ->assertSet('showImportModal', false)
            ->assertSet('importErrors', []);

        $this->assertDatabaseHas('stocks', ['user_id' => $this->member->id, 'name' => 'Imported Item', 'status' => 'available']);

        Livewire::test(StockList::class)
            ->call('openImportModal')
            ->set('importFile', $this->xlsxUpload([
                StockExcelService::HEADERS,
                [1, '', 'X', '', 1, 'pcs', 1, 1, '', ''],
            ]))
            ->call('importExcel')
            ->assertSet('showImportModal', true)
            ->assertSee('Baris 2: Name wajib diisi');
    }

    public function test_import_excel_validation_and_invalid_content(): void
    {
        $this->actingAs($this->member);

        Livewire::test(StockList::class)
            ->call('importExcel')
            ->assertHasErrors(['importFile' => 'required']);

        Livewire::test(StockList::class)
            ->set('importFile', UploadedFile::fake()->create('virus.exe', 10))
            ->call('importExcel')
            ->assertHasErrors(['importFile']);

        Livewire::test(StockList::class)
            ->set('importFile', UploadedFile::fake()->createWithContent('broken.xlsx', 'definitely not a zip'))
            ->call('importExcel')
            ->assertHasErrors(['importFile']);

        Livewire::test(StockList::class)
            ->set('importFile', $this->xlsxUpload([['Foo', 'Bar'], [1, 2]]))
            ->call('importExcel')
            ->assertHasErrors(['importFile'])
            ->call('closeImportModal')
            ->assertSet('showImportModal', false);

        $this->assertSame(0, Stock::count());
    }

    public function test_ai_generation_preview_and_saving(): void
    {
        $this->actingAs($this->member);

        $stub = $this->createStub(AiClientInterface::class);
        $stub->method('generateStocks')->willReturn([
            new GeneratedStockItem('AI Kopi', 'Kopi arabika', 3, 'kg', 120000.0, 5),
            new GeneratedStockItem('AI Teh'),
        ]);
        $this->app->instance(AiClientInterface::class, $stub);

        Livewire::test(StockList::class)
            ->call('openAiModal')
            ->assertSet('showAiModal', true)
            ->assertSet('aiCount', 3)
            ->set('aiPrompt', 'Stok warung kopi')
            ->set('aiCount', 2)
            ->call('generateWithAi')
            ->assertHasNoErrors()
            ->assertSet('aiErrorMessage', null)
            ->assertSet('aiIsGenerating', false)
            ->assertSet('aiSelectedIndices', [0, 1])
            ->assertSee('AI Kopi')
            ->set('aiSelectedIndices', [0])
            ->call('saveAiGenerated')
            ->assertSet('showAiModal', false);

        $this->assertDatabaseHas('stocks', ['user_id' => $this->member->id, 'name' => 'AI Kopi', 'quantity' => 3, 'status' => 'low_stock']);
        $this->assertDatabaseMissing('stocks', ['name' => 'AI Teh']);
    }

    public function test_ai_generation_handles_client_errors_and_validation(): void
    {
        $this->actingAs($this->member);

        $stub = $this->createStub(AiClientInterface::class);
        $stub->method('generateStocks')->willThrowException(new \RuntimeException('OpenAI API Error: down'));
        $this->app->instance(AiClientInterface::class, $stub);

        Livewire::test(StockList::class)
            ->call('openAiModal')
            ->set('aiPrompt', 'ab')
            ->call('generateWithAi')
            ->assertHasErrors(['aiPrompt']);

        Livewire::test(StockList::class)
            ->set('aiPrompt', 'Barang gudang')
            ->set('aiCount', 99)
            ->call('generateWithAi')
            ->assertHasErrors(['aiCount']);

        Livewire::test(StockList::class)
            ->set('aiPrompt', 'Barang gudang')
            ->call('generateWithAi')
            ->assertSet('aiErrorMessage', 'OpenAI API Error: down')
            ->assertSet('aiIsGenerating', false)
            ->assertSet('aiGeneratedItems', []);
    }

    public function test_save_ai_generated_requires_selection_and_skips_unknown_indices(): void
    {
        $this->actingAs($this->member);

        Livewire::test(StockList::class)
            ->call('saveAiGenerated')
            ->assertSet('aiErrorMessage', 'Pilih minimal satu item stok yang ingin disimpan.')
            ->set('aiGeneratedItems', [['name' => 'Only One']])
            ->set('aiSelectedIndices', [0, 7])
            ->call('saveAiGenerated');

        $this->assertSame(1, Stock::where('name', 'Only One')->count());
        $this->assertSame(1, Stock::count());
    }

    public function test_ai_modal_close(): void
    {
        $this->actingAs($this->member);

        Livewire::test(StockList::class)
            ->call('openAiModal')
            ->call('closeAiModal')
            ->assertSet('showAiModal', false);
    }
}
