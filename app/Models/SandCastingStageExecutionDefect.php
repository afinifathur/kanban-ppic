<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SandCastingStageExecutionDefect extends Model
{
    protected $fillable = [
        'sand_casting_stage_execution_id',
        'defect_type_id',
        'qty',
        'notes',
    ];

    protected $casts = [
        'qty' => 'integer',
    ];

    public function stageExecution()
    {
        return $this->belongsTo(SandCastingStageExecution::class, 'sand_casting_stage_execution_id');
    }

    public function defectType()
    {
        return $this->belongsTo(DefectType::class, 'defect_type_id');
    }
}
