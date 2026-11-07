<?php

namespace Database\Seeders;

use App\Features\Stock\Enums\StockStatus;
use App\Features\Stock\Models\Stock;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Administrator',
                'username' => 'admin',
                'password' => Hash::make('admin'),
                'role' => 'admin',
            ]
        );

        $member = User::firstOrCreate(
            ['email' => 'member@example.com'],
            [
                'name' => 'Member User',
                'username' => 'member',
                'password' => Hash::make('member'),
                'role' => 'member',
            ]
        );

        $samples = [
            ['sku' => 'BRG-001', 'name' => 'Beras Premium 5kg', 'quantity' => 40, 'unit' => 'karung', 'price' => 72000, 'min_stock' => 10],
            ['sku' => 'BRG-002', 'name' => 'Minyak Goreng 2L', 'quantity' => 4, 'unit' => 'botol', 'price' => 38000, 'min_stock' => 10],
            ['sku' => 'BRG-003', 'name' => 'Gula Pasir 1kg', 'quantity' => 0, 'unit' => 'kg', 'price' => 16500, 'min_stock' => 5],
        ];

        foreach ($samples as $sample) {
            Stock::firstOrCreate(
                ['user_id' => $member->id, 'sku' => $sample['sku']],
                $sample + [
                    'description' => 'Contoh stok **'.$sample['name'].'** untuk keperluan demo.',
                    'status' => StockStatus::fromQuantity($sample['quantity'], $sample['min_stock'])->value,
                ]
            );
        }
    }
}
