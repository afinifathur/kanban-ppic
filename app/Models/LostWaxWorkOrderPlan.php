<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LostWaxWorkOrderPlan extends Model
{
    protected $fillable = [
        'work_order_id',
        'wave_number',
        'plan_type',
        'planned_quantity',
        'status',
        'reason',
        'notes',
    ];

    protected $casts = [
        'wave_number' => 'integer',
        'planned_quantity' => 'integer',
    ];

    public function workOrder()
    {
        return $this->belongsTo(LostWaxWorkOrder::class, 'work_order_id');
    }
}
