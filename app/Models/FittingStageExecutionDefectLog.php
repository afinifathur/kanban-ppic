<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FittingStageExecutionDefectLog extends Model
{
    protected $fillable = [
        'fitting_stage_execution_id',
        'added_qty',
        'previous_total',
        'new_total',
        'user_id',
        'is_system_action',
        'notes',
        'qc_snapshot_json',
        'idempotency_key',
    ];

    protected $casts = [
        'added_qty' => 'integer',
        'previous_total' => 'integer',
        'new_total' => 'integer',
        'is_system_action' => 'boolean',
        'qc_snapshot_json' => 'array',
    ];

    public function stageExecution(): BelongsTo
    {
        return $this->belongsTo(\App\Models\FittingStageExecution::class, 'fitting_stage_execution_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }
}
