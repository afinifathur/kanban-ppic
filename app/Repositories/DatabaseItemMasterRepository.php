<?php

namespace App\Repositories;

use App\Contracts\ItemMasterRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class DatabaseItemMasterRepository implements ItemMasterRepository
{
    public function allActive(): Collection
    {
        try {
            return DB::connection($this->connection())
                ->table($this->table())
                ->where('status', 'active')
                ->orderBy($this->codeColumn())
                ->get()
                ->map(fn ($row) => $this->normalize($row));
        } catch (Throwable) {
            return collect();
        }
    }

    public function findByCode(string $code): ?array
    {
        try {
            $row = DB::connection($this->connection())
                ->table($this->table())
                ->where($this->codeColumn(), $code)
                ->where('status', 'active')
                ->first();

            return $row ? $this->normalize($row) : null;
        } catch (Throwable) {
            return null;
        }
    }

    protected function connection(): string
    {
        return (string) config('lost_wax.masterdata.connection');
    }

    protected function table(): string
    {
        return (string) config('lost_wax.masterdata.table');
    }

    protected function codeColumn(): string
    {
        return (string) config('lost_wax.masterdata.code_column');
    }

    protected function nameColumn(): string
    {
        return (string) config('lost_wax.masterdata.name_column');
    }

    protected function normalize(object $row): array
    {
        $codeColumn = $this->codeColumn();
        $nameColumn = $this->nameColumn();

        return [
            'code' => $row->{$codeColumn} ?? null,
            'name' => $row->{$nameColumn} ?? null,
            'aisi' => $row->aisi ?? null,
            'standard' => $row->standard ?? null,
            'unit_weight' => $row->unit_weight ?? null,
            'department_code' => $row->department_code ?? null,
            'cycle_time_sec' => $row->cycle_time_sec ?? null,
            'status' => $row->status ?? null,
        ];
    }
}
