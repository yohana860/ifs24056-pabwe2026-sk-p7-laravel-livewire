<?php

namespace Tests\Unit\Services;

use App\Features\Stock\Enums\StockStatus;
use App\Features\Stock\Models\Stock;
use App\Features\Stock\Repositories\StockRepository;
use App\Features\Stock\Services\StockExcelService;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class StockExcelServiceTest extends TestCase
{
    use RefreshDatabase;

    private StockExcelService $service;

    /** @var array<int, string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new StockExcelService(new StockRepository);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function makeXlsx(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'stk').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $this->tempFiles[] = $path;

        return $path;
    }

    public function test_build_spreadsheet_contains_headers_and_database_rows(): void
    {
        $user = User::factory()->create();
        Stock::factory()->create([
            'user_id' => $user->id, 'name' => 'Beras', 'sku' => 'BR-1', 'description' => 'Premium',
            'quantity' => 3, 'unit' => 'karung', 'price' => 70000, 'min_stock' => 5, 'status' => 'low_stock',
        ]);
        Stock::factory()->create([
            'user_id' => $user->id, 'name' => 'Tanpa Data', 'sku' => null, 'description' => null, 'price' => null,
        ]);

        $sheet = $this->service->buildSpreadsheet($user->stocks()->orderBy('id')->get(), str_repeat('X', 40))->getActiveSheet();

        $this->assertSame(31, strlen($sheet->getTitle()));
        foreach (StockExcelService::HEADERS as $i => $header) {
            $this->assertSame($header, $sheet->getCell(chr(65 + $i).'1')->getValue());
        }

        $this->assertSame('Beras', $sheet->getCell('B2')->getValue());
        $this->assertSame('BR-1', $sheet->getCell('C2')->getValue());
        $this->assertEquals(3, $sheet->getCell('E2')->getValue());
        $this->assertSame('karung', $sheet->getCell('F2')->getValue());
        $this->assertEquals(70000, $sheet->getCell('G2')->getValue());
        $this->assertEquals(5, $sheet->getCell('H2')->getValue());
        $this->assertSame('low_stock', $sheet->getCell('I2')->getValue());

        $this->assertSame('-', $sheet->getCell('C3')->getValue());
        $this->assertSame('-', $sheet->getCell('D3')->getValue());
        $this->assertSame('-', $sheet->getCell('G3')->getValue());
    }

    public function test_build_spreadsheet_with_no_stocks(): void
    {
        $sheet = $this->service->buildSpreadsheet(new Collection)->getActiveSheet();

        $this->assertSame('Name', $sheet->getCell('B1')->getValue());
        $this->assertNull($sheet->getCell('B2')->getValue());
    }

    public function test_build_spreadsheet_handles_missing_created_at(): void
    {
        $stock = new Stock(['name' => 'Unsaved', 'quantity' => 1, 'unit' => 'pcs', 'min_stock' => 5]);
        $stock->status = StockStatus::LOW_STOCK;

        $sheet = $this->service->buildSpreadsheet(new Collection([$stock]))->getActiveSheet();

        $this->assertSame('-', $sheet->getCell('J2')->getValue());
    }

    public function test_export_to_response(): void
    {
        $user = User::factory()->create();
        Stock::factory()->create(['user_id' => $user->id]);

        $response = $this->service->exportToResponse($user->stocks()->get(), 'stok-test.xlsx');

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertStringContainsString('spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('stok-test.xlsx', $response->headers->get('Content-Disposition'));

        ob_start();
        $response->sendContent();
        $content = ob_get_clean();
        $this->assertStringStartsWith('PK', $content); // xlsx is a zip container
    }

    public function test_import_valid_rows_recomputes_status(): void
    {
        $user = User::factory()->create();
        $path = $this->makeXlsx([
            StockExcelService::HEADERS,
            [1, 'Beras', 'BR-1', 'Premium', 50, 'karung', 70000, 10, 'out_of_stock', ''],
            [2, 'Gula', 'GL-1', '-', 4, 'kg', '-', 5, 'available', ''],
            [3, 'Teh', '-', '', 0, '', '', '', '', ''],
        ]);

        $result = $this->service->import($path, $user->id);

        $this->assertSame(3, $result['imported']);
        $this->assertSame([], $result['errors']);

        $beras = Stock::where('name', 'Beras')->first();
        $this->assertSame($user->id, $beras->user_id);
        $this->assertSame(StockStatus::AVAILABLE, $beras->status); // status column ignored, recomputed
        $this->assertSame('70000.00', $beras->price);

        $gula = Stock::where('name', 'Gula')->first();
        $this->assertSame(StockStatus::LOW_STOCK, $gula->status);
        $this->assertNull($gula->price);
        $this->assertNull($gula->description);

        $teh = Stock::where('name', 'Teh')->first();
        $this->assertSame(StockStatus::OUT_OF_STOCK, $teh->status);
        $this->assertNull($teh->sku);
        $this->assertSame('pcs', $teh->unit);
        $this->assertSame(5, $teh->min_stock);
    }

    public function test_import_accepts_quantity_header_alias_and_column_order(): void
    {
        $user = User::factory()->create();
        $path = $this->makeXlsx([
            ['Quantity', 'name', 'SKU'],
            [7, 'Reordered', 'RE-1'],
        ]);

        $result = $this->service->import($path, $user->id);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(7, Stock::where('sku', 'RE-1')->first()->quantity);
    }

    public function test_import_reports_invalid_rows_and_imports_valid_ones(): void
    {
        $user = User::factory()->create();
        Stock::factory()->create(['user_id' => $user->id, 'sku' => 'TAKEN']);

        $path = $this->makeXlsx([
            StockExcelService::HEADERS,
            [1, '', 'A', '', 1, 'pcs', 1, 1, '', ''],                 // 2: missing name
            [2, 'Dup DB', 'taken', '', 1, 'pcs', 1, 1, '', ''],       // 3: SKU exists in DB (case-insensitive)
            [3, 'Neg Qty', 'N-1', '', -1, 'pcs', 1, 1, '', ''],       // 4: negative qty
            [4, 'Frac Qty', 'N-2', '', 1.5, 'pcs', 1, 1, '', ''],     // 5: fractional qty
            [5, 'Text Qty', 'N-3', '', 'abc', 'pcs', 1, 1, '', ''],   // 6: non numeric qty
            [6, 'Bad Min', 'N-4', '', 1, 'pcs', 1, 'x', '', ''],      // 7: bad min stock
            [7, 'Bad Price', 'N-5', '', 1, 'pcs', 'abc', 1, '', ''],  // 8: non numeric price
            [8, 'Neg Price', 'N-6', '', 1, 'pcs', -5, 1, '', ''],     // 9: negative price
            [null, null, null, null, null, null, null, null, null, null], // 10: blank row in the middle is ignored
            [9, 'Good', 'G-1', '', 1, 'pcs', 1, 1, '', ''],           // 11: ok
            [10, 'Dup File', 'G-1', '', 1, 'pcs', 1, 1, '', ''],      // 12: SKU duplicated in file
        ]);

        $result = $this->service->import($path, $user->id);

        $this->assertSame(1, $result['imported']);
        $this->assertCount(9, $result['errors']);
        $this->assertStringContainsString('Baris 2: Name wajib diisi', $result['errors'][0]);
        $this->assertStringContainsString('Baris 3: SKU taken sudah digunakan', $result['errors'][1]);
        $this->assertStringContainsString('Baris 4: Qty', $result['errors'][2]);
        $this->assertStringContainsString('Baris 5: Qty', $result['errors'][3]);
        $this->assertStringContainsString('Baris 6: Qty', $result['errors'][4]);
        $this->assertStringContainsString('Baris 7: Min Stock', $result['errors'][5]);
        $this->assertStringContainsString('Baris 8: Price', $result['errors'][6]);
        $this->assertStringContainsString('Baris 9: Price', $result['errors'][7]);
        $this->assertStringContainsString('Baris 12: SKU G-1', $result['errors'][8]);
        $this->assertDatabaseHas('stocks', ['user_id' => $user->id, 'name' => 'Good']);
        $this->assertDatabaseMissing('stocks', ['name' => 'Dup File']);
    }

    public function test_import_with_only_header_imports_nothing(): void
    {
        $user = User::factory()->create();

        $result = $this->service->import($this->makeXlsx([StockExcelService::HEADERS]), $user->id);

        $this->assertSame(['imported' => 0, 'errors' => []], $result);
    }

    public function test_import_rejects_unreadable_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bad').'.xlsx';
        file_put_contents($path, "\x00\x01 not a spreadsheet");
        $this->tempFiles[] = $path;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('File tidak dapat dibaca');
        $this->service->import($path, 1);
    }

    public function test_import_rejects_file_without_name_header(): void
    {
        $path = $this->makeXlsx([['Foo', 'Bar'], ['1', '2']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Kolom "Name" wajib ada');
        $this->service->import($path, 1);
    }
}
