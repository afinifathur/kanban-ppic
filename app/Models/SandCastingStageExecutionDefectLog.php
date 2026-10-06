<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SandCastingStageExecutionDefectLog extends Model
{
    protected $fillable = [
        'sand_casting_stage_execution_id',
        'added_qty',
        'previous_total',
        'new_total',
        'user_id',
        'notes',
    ];

    protected $casts = [
        'added_qty' => 'integer',
        'previous_total' => 'integer',
        'new_total' => 'integer',
    ];

    public function stageExecution()
    {
        return $this->belongsTo(SandCastingStageExecution::class, 'sand_casting_stage_execution_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
