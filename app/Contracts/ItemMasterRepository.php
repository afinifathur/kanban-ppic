<?php

namespace App\Contracts;

use Illuminate\Support\Collection;

interface ItemMasterRepository
{
    public function allActive(): Collection;

    public function findByCode(string $code): ?array;
}
