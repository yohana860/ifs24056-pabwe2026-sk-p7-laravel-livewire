<?php

namespace App\Features\Stock\Repositories;

use App\Features\Stock\Contracts\StockRepositoryInterface;
use App\Features\Stock\Models\Stock;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class StockRepository implements StockRepositoryInterface
{
    public function forUser(int $userId, ?string $status = null, ?string $search = null, int $perPage = 10): LengthAwarePaginator
    {
        $query = Stock::query()->where('user_id', $userId);

        if (! empty($status)) {
            $query->where('status', $status);
        }

        if (! empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        return $query->latest('id')->paginate($perPage);
    }

    /**
     * @return Collection<int, Stock>
     */
    public function allForUser(int $userId): Collection
    {
        return Stock::query()
            ->where('user_id', $userId)
            ->latest('id')
            ->get();
    }

    /**
     * @return array<int, string>
     */
    public function skusForUser(int $userId): array
    {
        return Stock::query()
            ->where('user_id', $userId)
            ->whereNotNull('sku')
            ->pluck('sku')
            ->all();
    }

    public function find(int $id): ?Stock
    {
        return Stock::find($id);
    }

    public function findOrFail(int $id): Stock
    {
        return Stock::findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Stock
    {
        return Stock::create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Stock $stock, array $attributes): bool
    {
        return $stock->update($attributes);
    }

    public function delete(Stock $stock): bool
    {
        return (bool) $stock->delete();
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     */
    public function insertMany(array $records): bool
    {
        if (empty($records)) {
            return true;
        }

        return Stock::insert($records);
    }
}
