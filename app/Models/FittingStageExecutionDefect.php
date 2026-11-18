<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FittingStageExecutionDefect extends Model
{
    protected $fillable = [
        'fitting_stage_execution_id',
        'defect_type_id',
        'qty',
        'notes',
    ];

    protected $casts = [
        'qty' => 'integer',
    ];

    public function stageExecution(): BelongsTo
    {
        return $this->belongsTo(\App\Models\FittingStageExecution::class, 'fitting_stage_execution_id');
    }

    public function defectType(): BelongsTo
    {
        return $this->belongsTo(\App\Models\DefectType::class, 'defect_type_id');
    }
}
