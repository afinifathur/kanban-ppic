<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SandCastingStageExecution extends Model
{
    protected $fillable = [
        'sand_casting_casting_result_line_id',
        'stage',
        'input_qty',
        'defect_qty',
        'good_qty',
        'operator_id',
        'executed_at',
        'notes',
    ];

    protected $casts = [
        'input_qty' => 'integer',
        'defect_qty' => 'integer',
        'good_qty' => 'integer',
        'executed_at' => 'datetime',
    ];

    public function castingResultLine()
    {
        return $this->belongsTo(SandCastingCastingResultLine::class, 'sand_casting_casting_result_line_id');
    }

    public function operator()
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}
