<?php

namespace App\Features\Stock\Livewire;

use App\Features\Ai\Contracts\AiClientInterface;
use App\Features\Stock\Enums\StockStatus;
use App\Features\Stock\Services\StockExcelService;
use App\Features\Stock\Services\StockService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('components.layouts.app')]
#[Title('Stok Saya - StockApp')]
class StockList extends Component
{
    use WithFileUploads, WithPagination;

    /** @var array{type?: string, message?: string} */
    public array $notice = [];

    // Filters
    public string $search = '';

    public string $statusFilter = '';

    // Create / Edit Modal State
    public bool $showFormModal = false;

    public ?int $editingStockId = null;

    public string $formName = '';

    public ?string $formSku = null;

    public ?string $formDescription = null;

    public int|string $formQuantity = 0;

    public string $formUnit = 'pcs';

    public int|string|null $formPrice = null;

    public int|string $formMinStock = 5;

    // Delete Confirmation Modal State
    public ?int $confirmingStockDeletionId = null;

    // Excel Import State
    public bool $showImportModal = false;

    public mixed $importFile = null;

    /** @var array<int, string> */
    public array $importErrors = [];

    // AI Generator State
    public bool $showAiModal = false;

    public string $aiPrompt = '';

    public int $aiCount = 3;

    public array $aiGeneratedItems = [];

    public array $aiSelectedIndices = [];

    public bool $aiIsGenerating = false;

    public ?string $aiErrorMessage = null;

    protected $queryString = [
        'search' => ['except' => ''],
        'statusFilter' => ['except' => ''],
    ];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    // --- CRUD Operations ---

    public function openCreateModal(): void
    {
        $this->resetForm();
        $this->showFormModal = true;
    }

    public function openEditModal(int $id, StockService $stockService): void
    {
        $stock = $stockService->findStock($id);
        Gate::authorize('update', $stock);

        $this->resetErrorBag();
        $this->editingStockId = $stock->id;
        $this->formName = $stock->name;
        $this->formSku = $stock->sku;
        $this->formDescription = $stock->description;
        $this->formQuantity = $stock->quantity;
        $this->formUnit = $stock->unit;
        $this->formPrice = $stock->price !== null ? (string) $stock->price : null;
        $this->formMinStock = $stock->min_stock;
        $this->showFormModal = true;
    }

    public function closeFormModal(): void
    {
        $this->showFormModal = false;
    }

    public function resetForm(): void
    {
        $this->reset(['editingStockId', 'formName', 'formSku', 'formDescription', 'formPrice']);
        $this->formQuantity = 0;
        $this->formUnit = 'pcs';
        $this->formMinStock = 5;
        $this->resetErrorBag();
    }

    public function saveStock(StockService $stockService): void
    {
        $this->formSku = $this->formSku === '' ? null : $this->formSku;
        $this->formPrice = $this->formPrice === '' ? null : $this->formPrice;

        $this->validate([
            'formName' => ['required', 'string', 'max:255'],
            'formSku' => [
                'nullable', 'string', 'max:100',
                Rule::unique('stocks', 'sku')
                    ->where('user_id', Auth::id())
                    ->ignore($this->editingStockId),
            ],
            'formDescription' => ['nullable', 'string'],
            'formQuantity' => ['required', 'integer', 'min:0'],
            'formUnit' => ['required', 'string', 'max:50'],
            'formPrice' => ['nullable', 'numeric', 'min:0'],
            'formMinStock' => ['required', 'integer', 'min:0'],
        ]);

        $data = [
            'name' => $this->formName,
            'sku' => $this->formSku,
            'description' => $this->formDescription,
            'quantity' => (int) $this->formQuantity,
            'unit' => $this->formUnit,
            'price' => $this->formPrice,
            'min_stock' => (int) $this->formMinStock,
        ];

        if ($this->editingStockId) {
            $stock = $stockService->findStock($this->editingStockId);
            Gate::authorize('update', $stock);
            $stockService->updateStock($stock, $data);
            $this->notify('success', 'Stok berhasil diperbarui.');
        } else {
            $stockService->createStock(Auth::id(), $data);
            $this->notify('success', 'Stok baru berhasil ditambahkan.');
        }

        $this->closeFormModal();
    }

    public function adjustQuantity(int $id, int $delta, StockService $stockService): void
    {
        $stock = $stockService->findStock($id);
        Gate::authorize('update', $stock);

        try {
            $stockService->adjustQuantity($stock, $delta);
            $this->notify('success', 'Quantity stok berhasil diperbarui.');
        } catch (DomainException $e) {
            $this->notify('error', $e->getMessage());
        }
    }

    public function confirmDelete(int $id): void
    {
        $this->confirmingStockDeletionId = $id;
    }

    public function cancelDelete(): void
    {
        $this->confirmingStockDeletionId = null;
    }

    public function deleteStock(?int $id = null, ?StockService $stockService = null): void
    {
        $stockService = $stockService ?? app(StockService::class);
        $targetId = $id ?? $this->confirmingStockDeletionId;

        if ($targetId) {
            $stock = $stockService->findStock($targetId);
            Gate::authorize('delete', $stock);

            $stockService->deleteStock($stock);
            $this->confirmingStockDeletionId = null;
            $this->notify('success', 'Stok berhasil dihapus.');
        }
    }

    // --- Excel Export & Import ---

    public function exportExcel(StockExcelService $excelService): StreamedResponse
    {
        $stocks = Auth::user()->stocks()->latest('id')->get();

        return $excelService->exportToResponse($stocks, 'stok-'.now()->format('Ymd-His').'.xlsx');
    }

    public function openImportModal(): void
    {
        $this->reset(['importFile', 'importErrors']);
        $this->resetErrorBag();
        $this->showImportModal = true;
    }

    public function closeImportModal(): void
    {
        $this->showImportModal = false;
    }

    public function importExcel(StockExcelService $excelService): void
    {
        $this->validate([
            'importFile' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        try {
            $result = $excelService->import($this->importFile->getRealPath(), Auth::id());
        } catch (RuntimeException $e) {
            $this->addError('importFile', $e->getMessage());

            return;
        }

        $this->importErrors = $result['errors'];
        $this->notify('success', "Berhasil mengimpor {$result['imported']} item stok.");

        if (empty($result['errors'])) {
            $this->closeImportModal();
        }
    }

    // --- AI Stock Generator ---

    public function openAiModal(): void
    {
        $this->reset(['aiPrompt', 'aiGeneratedItems', 'aiSelectedIndices', 'aiErrorMessage', 'aiIsGenerating']);
        $this->aiCount = 3;
        $this->showAiModal = true;
    }

    public function closeAiModal(): void
    {
        $this->showAiModal = false;
    }

    public function generateWithAi(AiClientInterface $aiClient): void
    {
        $this->validate([
            'aiPrompt' => ['required', 'string', 'min:3', 'max:500'],
            'aiCount' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        $this->aiIsGenerating = true;
        $this->aiErrorMessage = null;

        try {
            $items = $aiClient->generateStocks($this->aiPrompt, $this->aiCount);
            $this->aiGeneratedItems = array_map(fn ($item) => $item->toArray(), $items);
            $this->aiSelectedIndices = array_keys($this->aiGeneratedItems);
        } catch (\Throwable $e) {
            $this->aiErrorMessage = $e->getMessage();
        } finally {
            $this->aiIsGenerating = false;
        }
    }

    public function saveAiGenerated(StockService $stockService): void
    {
        if (empty($this->aiSelectedIndices) || empty($this->aiGeneratedItems)) {
            $this->aiErrorMessage = 'Pilih minimal satu item stok yang ingin disimpan.';

            return;
        }

        $savedCount = 0;
        foreach ($this->aiSelectedIndices as $index) {
            if (isset($this->aiGeneratedItems[$index])) {
                $item = $this->aiGeneratedItems[$index];
                $stockService->createStock(Auth::id(), [
                    'name' => $item['name'],
                    'description' => $item['description'] ?? null,
                    'quantity' => $item['quantity'] ?? 0,
                    'unit' => $item['unit'] ?? 'pcs',
                    'price' => $item['price'] ?? null,
                    'min_stock' => $item['min_stock'] ?? 5,
                ]);
                $savedCount++;
            }
        }

        $this->closeAiModal();
        $this->notify('success', "Berhasil menambahkan {$savedCount} stok yang digenerate oleh AI.");
    }

    /**
     * Show a flash-style notice inside the component (the layout is not re-rendered on Livewire updates).
     */
    protected function notify(string $type, string $message): void
    {
        $this->notice = ['type' => $type, 'message' => $message];
    }

    public function render(StockService $stockService): View
    {
        $stocks = $stockService->getStocksForUser(
            Auth::id(),
            $this->statusFilter ?: null,
            $this->search ?: null,
            8
        );

        return view('livewire.stock.stock-list', [
            'stocks' => $stocks,
            'statuses' => StockStatus::cases(),
        ]);
    }
}
