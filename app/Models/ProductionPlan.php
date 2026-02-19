<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionPlan extends Model
{
    protected $fillable = [
        'code',
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
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'qty_planned' => 'integer',
        'qty_remaining' => 'integer',
        'line_number' => 'integer',
        'weight' => 'decimal:2',
    ];

    public function items()
    {
        return $this->hasMany(ProductionItem::class, 'plan_id');
    }
}
