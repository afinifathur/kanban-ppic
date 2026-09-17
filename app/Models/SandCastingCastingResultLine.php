<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SandCastingCastingResultLine extends Model
{
    protected $fillable = [
        'sand_casting_casting_result_id',
        'sand_casting_casting_order_line_id',
        'production_plan_id',
        'traveler_number',
        'qty_good',
        'qty_reject',
        'unit_weight_kg',
        'total_weight_kg',
        'notes',
        'printed_at',
        'print_count',
        'last_printed_by',
    ];

    protected $casts = [
        'qty_good' => 'integer',
        'qty_reject' => 'integer',
        'unit_weight_kg' => 'decimal:2',
        'total_weight_kg' => 'decimal:2',
        'printed_at' => 'datetime',
        'print_count' => 'integer',
    ];

    public function castingResult()
    {
        return $this->belongsTo(SandCastingCastingResult::class, 'sand_casting_casting_result_id');
    }

    public function castingOrderLine()
    {
        return $this->belongsTo(SandCastingCastingOrderLine::class, 'sand_casting_casting_order_line_id');
    }

    public function productionPlan()
    {
        return $this->belongsTo(ProductionPlan::class, 'production_plan_id');
    }

    public function lastPrintedBy()
    {
        return $this->belongsTo(User::class, 'last_printed_by');
    }

    public function getQtyTotalAttribute(): int
    {
        return $this->qty_good + $this->qty_reject;
    }
}
