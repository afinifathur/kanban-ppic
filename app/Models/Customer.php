<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Customer extends Model
{
    use HasFactory;

    public const CATEGORY_LOCAL = 'LOCAL';

    public const CATEGORY_EXPORT = 'EXPORT';

    public const CATEGORY_UNCLASSIFIED = 'UNCLASSIFIED';

    protected $fillable = ['name', 'category', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Check whether this customer is referenced by any production plan, production item, or casting order line.
     */
    public function isUsedInProduction(): bool
    {
        $nameKey = strtoupper(trim((string) $this->name));
        if ($nameKey === '') {
            return false;
        }

        if (Schema::hasTable('production_plans') && \App\Models\ProductionPlan::whereRaw('UPPER(TRIM(customer)) = ?', [$nameKey])->exists()) {
            return true;
        }

        if (Schema::hasTable('sand_casting_casting_order_lines') && \App\Models\SandCastingCastingOrderLine::whereRaw('UPPER(TRIM(customer)) = ?', [$nameKey])->exists()) {
            return true;
        }

        if (Schema::hasTable('production_items') && \App\Models\ProductionItem::whereRaw('UPPER(TRIM(customer)) = ?', [$nameKey])->exists()) {
            return true;
        }

        return false;
    }

    /**
     * Build an uppercase customer name => category map from master customers.
     *
     * @return array<string, string>
     */
    public static function getCategoryMap(): array
    {
        if (! Schema::hasTable('customers') || ! Schema::hasColumn('customers', 'category')) {
            return [];
        }

        $map = [];
        foreach (static::query()->select(['name', 'category'])->get() as $customer) {
            $nameKey = strtoupper(trim((string) $customer->name));
            $cat = strtoupper(trim((string) $customer->category));
            if ($nameKey !== '' && in_array($cat, [self::CATEGORY_LOCAL, self::CATEGORY_EXPORT], true)) {
                $map[$nameKey] = $cat;
            }
        }

        return $map;
    }

    /**
     * Resolve customer market category according to strict business rules:
     * - STOK NON PO => LOCAL
     * - STOK EXP => EXPORT
     * - Other customers follow master customer category (LOCAL / EXPORT)
     * - Never guess from customer name; fallback to UNCLASSIFIED.
     *
     * @param  array<string, string>|null  $categoryMap
     */
    public static function resolveCategory(?string $customerName, ?array $categoryMap = null): string
    {
        $normalized = strtoupper(trim((string) $customerName));

        if ($normalized === '') {
            return self::CATEGORY_UNCLASSIFIED;
        }

        if ($normalized === 'STOK NON PO') {
            return self::CATEGORY_LOCAL;
        }

        if ($normalized === 'STOK EXP') {
            return self::CATEGORY_EXPORT;
        }

        $map = $categoryMap ?? self::getCategoryMap();
        $mapped = $map[$normalized] ?? null;

        if (in_array($mapped, [self::CATEGORY_LOCAL, self::CATEGORY_EXPORT], true)) {
            return $mapped;
        }

        return self::CATEGORY_UNCLASSIFIED;
    }
}
