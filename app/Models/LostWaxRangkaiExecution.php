<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LostWaxRangkaiExecution extends Model
{
    protected $table = 'lost_wax_rangkai_executions';

    protected $fillable = [
        'rangkai_work_order_id',
        'execution_date',
        'trees_created',
        'family_code',
        'recorded_by',
        'recorded_at',
    ];

    protected $casts = [
        'execution_date' => 'date',
        'trees_created' => 'integer',
        'recorded_at' => 'datetime',
    ];

    public function workOrder()
    {
        return $this->belongsTo(LostWaxRangkaiWorkOrder::class, 'rangkai_work_order_id');
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function trees()
    {
        return $this->hasMany(LostWaxTree::class, 'rangkai_execution_id');
    }
}
