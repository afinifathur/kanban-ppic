<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SandCastingStageExecution extends Model
{
    public const STATUS_READY = 'READY';

    public const STATUS_PHYSICAL_DONE = 'PHYSICAL_DONE';

    public const STATUS_WAITING_DEFECT = 'WAITING_DEFECT';

    public const STATUS_WAITING_QC = 'WAITING_QC';

    public const STATUS_CONFIRMED = 'CONFIRMED';

    protected $fillable = [
        'sand_casting_casting_result_line_id',
        'stage',
        'checkpoint_code',
        'input_qty',
        'defect_qty',
        'good_qty',
        'status',
        'operator_id',
        'executed_at',
        'physical_done_at',
        'defect_entered_at',
        'defect_entered_by',
        'qc_verified_at',
        'qc_verified_by',
        'notes',
    ];

    protected $casts = [
        'input_qty' => 'integer',
        'defect_qty' => 'integer',
        'good_qty' => 'integer',
        'executed_at' => 'datetime',
        'physical_done_at' => 'datetime',
        'defect_entered_at' => 'datetime',
        'qc_verified_at' => 'datetime',
    ];

    public function castingResultLine()
    {
        return $this->belongsTo(SandCastingCastingResultLine::class, 'sand_casting_casting_result_line_id');
    }

    public function operator()
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function defectEnteredBy()
    {
        return $this->belongsTo(User::class, 'defect_entered_by');
    }

    public function qcVerifiedBy()
    {
        return $this->belongsTo(User::class, 'qc_verified_by');
    }

    public function defects()
    {
        return $this->hasMany(SandCastingStageExecutionDefect::class, 'sand_casting_stage_execution_id');
    }
}
