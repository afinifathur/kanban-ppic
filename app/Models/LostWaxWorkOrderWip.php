<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LostWaxWorkOrderWip extends Model
{
    protected $fillable = [
        'work_order_id',
        'work_order_plan_id',
        'stage',
        'quantity',
        'status',
        'notes',
        'produced_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'produced_at' => 'datetime',
    ];

    public function workOrder()
    {
        return $this->belongsTo(LostWaxWorkOrder::class, 'work_order_id');
    }

    public function plan()
    {
        return $this->belongsTo(LostWaxWorkOrderPlan::class, 'work_order_plan_id');
    }
}
