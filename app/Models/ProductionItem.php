<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionItem extends Model
{
    protected $fillable = [
        'plan_id',
        'code',
        'item_code',
        'heat_number',
        'item_name',
        'aisi',
        'size',
        'qty_pcs',
        'scrap_qty',
        'weight_kg',
        'bruto_weight',
        'netto_weight',
        'bubut_weight',
        'finish_weight',
        'po_number',
        'customer',
        'current_dept',
        'line_number',
        'dept_entry_at',
        'production_date',
        'remarks',
    ];

    protected $casts = [
        'dept_entry_at' => 'datetime',
        'production_date' => 'date',
        'weight_kg' => 'decimal:2',
        'bruto_weight' => 'decimal:2',
        'netto_weight' => 'decimal:2',
        'bubut_weight' => 'decimal:2',
        'finish_weight' => 'decimal:2',
        'scrap_qty' => 'integer',
    ];

    public function plan()
    {
        return $this->belongsTo(ProductionPlan::class, 'plan_id');
    }

    public function defects()
    {
        return $this->hasMany(ProductionDefect::class, 'production_item_id');
    }

    public function getAgingDaysAttribute()
    {
        $baseDate = $this->production_date ?? $this->dept_entry_at;
        return $baseDate->diffInDays(now()->startOfDay());
    }

    public function getAgingColorAttribute()
    {
        $days = $this->aging_days;

        if ($days < 7)
            return 'green';
        if ($days <= 14)
            return 'yellow';
        if ($days <= 21)
            return 'orange';
        return 'red';
    }
}
