<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionPlan extends Model
{
    protected $fillable = [
        'code',
        'title',
        'item_code',
        'item_name',
        'aisi',
        'size',
        'weight',
        'po_number',
        'po_quantity',
        'qty_planned',
        'qty_remaining',
        'line_number',
        'customer',
        'product_scope',
        'status',
        'is_closed',
        'closure_reason',
        'closed_by',
        'closed_at',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'po_quantity' => 'integer',
        'qty_planned' => 'integer',
        'qty_remaining' => 'integer',
        'line_number' => 'integer',
        'weight' => 'decimal:2',
        'is_closed' => 'boolean',
        'closed_at' => 'datetime',
    ];

    public static function determineProductScopeFromItem(string $itemName, ?string $aisi = null): ?string
    {
        $itemNameLower = strtolower($itemName);
        $aisiLower = $aisi ? strtolower($aisi) : '';

        // Check if it's fitting first
        if (str_contains($itemNameLower, 'fitting') || str_contains($itemNameLower, 'elbow') || str_contains($itemNameLower, 'tee') || str_contains($itemNameLower, 'reducer')) {
            return 'FITTING_STAINLESS';
        }

        // Check if it's flange
        if (str_contains($itemNameLower, 'flange') || str_contains($itemNameLower, 'blind')) {
            if (str_contains($itemNameLower, 'besi') || str_contains($itemNameLower, 'iron') || str_contains($itemNameLower, 'steel') && ! str_contains($itemNameLower, 'stainless') && ! str_contains($itemNameLower, 'ss')) {
                return 'FLANGE_BESI';
            }
            if (str_contains($itemNameLower, 'stainless') || str_contains($itemNameLower, 'ss') || str_contains($aisiLower, '304') || str_contains($aisiLower, '316') || str_contains($aisiLower, 'cf8') || str_contains($aisiLower, 'cf8m')) {
                return 'FLANGE_STAINLESS';
            }
        }

        return null;
    }

    public function items()
    {
        return $this->hasMany(ProductionItem::class, 'plan_id');
    }

    public function printOrderLines()
    {
        return $this->hasMany(LostWaxPrintOrderLine::class, 'production_plan_id');
    }

    public function getQtyScheduledAttribute(): int
    {
        if (array_key_exists('qty_scheduled', $this->attributes)) {
            return (int) $this->attributes['qty_scheduled'];
        }

        return (int) $this->printOrderLines()
            ->whereHas('printOrder', function ($query) {
                $query->whereIn('status', ['DRAFT', 'ISSUED']);
            })
            ->sum('qty_ordered');
    }

    public function getQtyRemainingScheduledAttribute(): int
    {
        return $this->qty_planned - $this->qty_scheduled;
    }

    public function getQtyProducedAttribute(): int
    {
        return (int) $this->printOrderLines()
            ->whereHas('printOrder', function ($query) {
                $query->where('status', '!=', 'CANCELLED');
            })
            ->sum('qty_executed_good');
    }

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function evaluateProductionStatus(int $usableQuantity): string
    {
        if ($usableQuantity >= $this->qty_planned) {
            return 'NORMAL';
        }

        if ($this->po_quantity !== null) {
            return $usableQuantity >= $this->po_quantity ? 'WARNING' : 'CRITICAL';
        }

        return 'WARNING';
    }

    public function getQtyRemainingToProduceAttribute(): int
    {
        return max(0, $this->qty_planned - $this->qty_produced);
    }
}
