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
        'qty_planned',
        'qty_remaining',
        'line_number',
        'customer',
        'status',
        'is_closed',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'qty_planned' => 'integer',
        'qty_remaining' => 'integer',
        'line_number' => 'integer',
        'weight' => 'decimal:2',
        'is_closed' => 'boolean',
    ];

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
}
