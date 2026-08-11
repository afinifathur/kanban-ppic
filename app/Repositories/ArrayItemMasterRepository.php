<?php

namespace App\Repositories;

use App\Contracts\ItemMasterRepository;
use Illuminate\Support\Collection;

class ArrayItemMasterRepository implements ItemMasterRepository
{
    public function __construct(private array $items = []) {}

    public function allActive(): Collection
    {
        return collect($this->items)
            ->filter(fn (array $item) => ($item['status'] ?? 'active') === 'active')
            ->values();
    }

    public function findByCode(string $code): ?array
    {
        return $this->allActive()
            ->first(fn (array $item) => (string) ($item['code'] ?? '') === $code);
    }
}
