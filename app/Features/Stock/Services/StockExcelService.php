<?php

namespace App\Features\Stock\Services;

use App\Features\Stock\Contracts\StockRepositoryInterface;
use App\Features\Stock\Enums\StockStatus;
use App\Features\Stock\Models\Stock;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class StockExcelService
{
    /**
     * Export / import column headers (A..J).
     *
     * @var array<int, string>
     */
    public const HEADERS = ['No', 'Name', 'SKU', 'Description', 'Qty', 'Unit', 'Price', 'Min Stock', 'Status', 'Created At'];

    public function __construct(
        protected StockRepositoryInterface $stockRepository
    ) {}

    /**
     * Build a Spreadsheet instance containing stock data.
     *
     * @param  Collection<int, Stock>  $stocks
     */
    public function buildSpreadsheet(Collection $stocks, string $sheetTitle = 'Stocks'): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(substr($sheetTitle, 0, 31));

        $columns = range('A', 'J');

        foreach (self::HEADERS as $index => $header) {
            $sheet->setCellValue("{$columns[$index]}1", $header);
        }

        $headerRange = 'A1:J1';
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2563EB');
        $sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $rowNumber = 2;
        foreach ($stocks as $idx => $stock) {
            $sheet->setCellValue("A{$rowNumber}", $idx + 1);
            $sheet->setCellValue("B{$rowNumber}", $stock->name);
            $sheet->setCellValue("C{$rowNumber}", $stock->sku ?? '-');
            $sheet->setCellValue("D{$rowNumber}", $stock->description ?? '-');
            $sheet->setCellValue("E{$rowNumber}", $stock->quantity);
            $sheet->setCellValue("F{$rowNumber}", $stock->unit);
            $sheet->setCellValue("G{$rowNumber}", $stock->price !== null ? (float) $stock->price : '-');
            $sheet->setCellValue("H{$rowNumber}", $stock->min_stock);
            $sheet->setCellValue("I{$rowNumber}", $stock->status->value);
            $sheet->setCellValue("J{$rowNumber}", $stock->created_at ? $stock->created_at->format('Y-m-d H:i') : '-');
            $rowNumber++;
        }

        $lastRow = max(2, $rowNumber - 1);
        $sheet->getStyle("A1:J{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        foreach ($columns as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return $spreadsheet;
    }

    /**
     * Export stocks as a downloadable streamed response.
     *
     * @param  Collection<int, Stock>  $stocks
     */
    public function exportToResponse(Collection $stocks, string $filename = 'stok.xlsx'): StreamedResponse
    {
        $spreadsheet = $this->buildSpreadsheet($stocks);

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    /**
     * Import stocks from an uploaded spreadsheet file path.
     *
     * Rows are validated individually: invalid rows are reported in `errors` and skipped,
     * valid rows are inserted. The status column is ignored and always recomputed from qty/min_stock.
     *
     * @return array{imported: int, errors: array<int, string>}
     *
     * @throws RuntimeException when the file cannot be read or has no valid header.
     */
    public function import(string $filePath, int $userId): array
    {
        try {
            $rows = IOFactory::load($filePath)->getActiveSheet()->toArray(null, true, true, true);
        } catch (Throwable) {
            throw new RuntimeException('File tidak dapat dibaca sebagai file Excel/CSV yang valid.');
        }

        // array_shift() would re-index the numeric keys, so keep the real spreadsheet row numbers.
        $headerKey = array_key_first($rows);
        $headerRow = $rows[$headerKey];
        unset($rows[$headerKey]);
        $map = $this->mapHeader($headerRow);

        if (! isset($map['name'])) {
            throw new RuntimeException('Header file tidak sesuai. Kolom "Name" wajib ada.');
        }

        $existingSkus = array_map('strtolower', $this->stockRepository->skusForUser($userId));
        $errors = [];
        $records = [];
        $now = Carbon::now();

        foreach ($rows as $rowNumber => $row) {
            $cell = fn (string $key): string => isset($map[$key]) ? trim((string) ($row[$map[$key]] ?? '')) : '';

            if (implode('', array_map(fn ($v) => trim((string) $v), $row)) === '') {
                continue; // fully empty row
            }

            $name = $cell('name');
            if ($name === '') {
                $errors[] = "Baris {$rowNumber}: Name wajib diisi.";

                continue;
            }

            $sku = $cell('sku');
            $sku = ($sku === '' || $sku === '-') ? null : $sku;
            if ($sku !== null) {
                if (in_array(strtolower($sku), $existingSkus, true)) {
                    $errors[] = "Baris {$rowNumber}: SKU {$sku} sudah digunakan.";

                    continue;
                }
                $existingSkus[] = strtolower($sku);
            }

            $quantity = $this->parseNonNegativeInt($cell('qty'), 0);
            if ($quantity === null) {
                $errors[] = "Baris {$rowNumber}: Qty harus berupa bilangan bulat >= 0.";

                continue;
            }

            $minStock = $this->parseNonNegativeInt($cell('min_stock'), 5);
            if ($minStock === null) {
                $errors[] = "Baris {$rowNumber}: Min Stock harus berupa bilangan bulat >= 0.";

                continue;
            }

            $priceRaw = $cell('price');
            $price = null;
            if ($priceRaw !== '' && $priceRaw !== '-') {
                if (! is_numeric($priceRaw) || (float) $priceRaw < 0) {
                    $errors[] = "Baris {$rowNumber}: Price harus berupa angka >= 0.";

                    continue;
                }
                $price = (float) $priceRaw;
            }

            $description = $cell('description');
            $unit = $cell('unit');

            $records[] = [
                'user_id' => $userId,
                'name' => substr($name, 0, 255),
                'sku' => $sku !== null ? substr($sku, 0, 255) : null,
                'description' => ($description === '' || $description === '-') ? null : $description,
                'quantity' => $quantity,
                'unit' => $unit === '' ? 'pcs' : substr($unit, 0, 50),
                'price' => $price,
                'min_stock' => $minStock,
                'status' => StockStatus::fromQuantity($quantity, $minStock)->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->stockRepository->insertMany($records);

        return [
            'imported' => count($records),
            'errors' => $errors,
        ];
    }

    /**
     * Map normalized header names to column letters.
     *
     * @param  array<string, mixed>  $headerRow
     * @return array<string, string>
     */
    protected function mapHeader(array $headerRow): array
    {
        $aliases = [
            'name' => 'name',
            'sku' => 'sku',
            'description' => 'description',
            'qty' => 'qty',
            'quantity' => 'qty',
            'unit' => 'unit',
            'price' => 'price',
            'minstock' => 'min_stock',
            'status' => 'status',
        ];

        $map = [];
        foreach ($headerRow as $column => $title) {
            $key = strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) $title));
            if (isset($aliases[$key])) {
                $map[$aliases[$key]] = (string) $column;
            }
        }

        return $map;
    }

    /**
     * Parse a non-negative integer cell. Blank cells fall back to the default.
     */
    protected function parseNonNegativeInt(string $value, int $default): ?int
    {
        if ($value === '') {
            return $default;
        }

        if (! is_numeric($value) || (float) $value < 0 || floor((float) $value) !== (float) $value) {
            return null;
        }

        return (int) $value;
    }
}
