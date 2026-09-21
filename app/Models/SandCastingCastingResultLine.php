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
        'current_stage',
        'is_urgent',
        'urgent_set_at',
        'urgent_set_by',
    ];

    protected $attributes = [
        'is_urgent' => false,
    ];

    protected $casts = [
        'qty_good' => 'integer',
        'qty_reject' => 'integer',
        'unit_weight_kg' => 'decimal:2',
        'total_weight_kg' => 'decimal:2',
        'printed_at' => 'datetime',
        'print_count' => 'integer',
        'is_urgent' => 'boolean',
        'urgent_set_at' => 'datetime',
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

    public function urgentSetBy()
    {
        return $this->belongsTo(User::class, 'urgent_set_by');
    }

    public function stageExecutions()
    {
        return $this->hasMany(SandCastingStageExecution::class, 'sand_casting_casting_result_line_id');
    }

    public function getQtyTotalAttribute(): int
    {
        return $this->qty_good + $this->qty_reject;
    }
}
