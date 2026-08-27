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
        'status',
        'variance_qty',
        'is_anomaly',
        'anomaly_notes',
        'recorded_by',
        'recorded_at',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected $casts = [
        'execution_date' => 'date',
        'trees_created' => 'integer',
        'variance_qty' => 'integer',
        'is_anomaly' => 'boolean',
        'recorded_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function workOrder()
    {
        return $this->belongsTo(LostWaxRangkaiWorkOrder::class, 'rangkai_work_order_id');
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function canceller()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function trees()
    {
        return $this->hasMany(LostWaxTree::class, 'rangkai_execution_id');
    }

    public function getIsCancelledAttribute(): bool
    {
        return $this->status === 'CANCELLED';
    }

    public function getIsScannedAttribute(): bool
    {
        return $this->trees->contains(function ($tree) {
            return $tree->current_stage !== null || $tree->scanEvents()->where('result', 'success')->exists();
        });
    }

    public function getCanBeCancelledAttribute(): bool
    {
        return ! $this->is_cancelled && ! $this->is_scanned;
    }
}
