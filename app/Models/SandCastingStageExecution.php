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
        'inspection_date',
        'is_auto_nihil',
        'auto_nihil_at',
        'notes',
    ];

    protected $casts = [
        'input_qty' => 'integer',
        'defect_qty' => 'integer',
        'good_qty' => 'integer',
        'inspection_date' => 'date',
        'is_auto_nihil' => 'boolean',
        'executed_at' => 'datetime',
        'physical_done_at' => 'datetime',
        'auto_nihil_at' => 'datetime',
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

    public function defectLogs()
    {
        return $this->hasMany(SandCastingStageExecutionDefectLog::class, 'sand_casting_stage_execution_id')
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc');
    }

    /**
     * Resolve effective business defect date following hierarchy:
     * inspection_date -> physical_done_at -> cast_date -> defect_entered_at -> qc_verified_at
     */
    public function resolveEffectiveDefectDate(): ?string
    {
        if ($this->inspection_date) {
            return $this->inspection_date instanceof \Carbon\CarbonInterface
                ? $this->inspection_date->toDateString()
                : substr((string) $this->inspection_date, 0, 10);
        }

        if ($this->physical_done_at) {
            return $this->physical_done_at instanceof \Carbon\CarbonInterface
                ? $this->physical_done_at->toDateString()
                : substr((string) $this->physical_done_at, 0, 10);
        }

        $castDate = $this->castingResultLine?->castingResult?->cast_date;
        if ($castDate) {
            return $castDate instanceof \Carbon\CarbonInterface
                ? $castDate->toDateString()
                : substr((string) $castDate, 0, 10);
        }

        if ($this->defect_entered_at) {
            return $this->defect_entered_at instanceof \Carbon\CarbonInterface
                ? $this->defect_entered_at->toDateString()
                : substr((string) $this->defect_entered_at, 0, 10);
        }

        if ($this->qc_verified_at) {
            return $this->qc_verified_at instanceof \Carbon\CarbonInterface
                ? $this->qc_verified_at->toDateString()
                : substr((string) $this->qc_verified_at, 0, 10);
        }

        return null;
    }

    /**
     * Scope query to match effective defect date on a single date.
     */
    public function scopeWhereEffectiveDefectDate($query, string $targetDate)
    {
        return $query->where(function ($sub) use ($targetDate) {
            $sub->where(function ($dq) use ($targetDate) {
                $dq->whereDate('sand_casting_stage_executions.inspection_date', $targetDate)
                    ->orWhereBetween('sand_casting_stage_executions.inspection_date', [$targetDate.' 00:00:00', $targetDate.' 23:59:59']);
            })
                ->orWhere(function ($sq) use ($targetDate) {
                    $sq->whereNull('sand_casting_stage_executions.inspection_date')
                        ->whereBetween('sand_casting_stage_executions.physical_done_at', [$targetDate.' 00:00:00', $targetDate.' 23:59:59']);
                })
                ->orWhere(function ($sq) use ($targetDate) {
                    $sq->whereNull('sand_casting_stage_executions.inspection_date')
                        ->whereNull('sand_casting_stage_executions.physical_done_at')
                        ->whereHas('castingResultLine.castingResult', function ($rq) use ($targetDate) {
                            $rq->whereDate('cast_date', $targetDate)
                                ->orWhereBetween('cast_date', [$targetDate.' 00:00:00', $targetDate.' 23:59:59']);
                        });
                })
                ->orWhere(function ($sq) use ($targetDate) {
                    $sq->whereNull('sand_casting_stage_executions.inspection_date')
                        ->whereNull('sand_casting_stage_executions.physical_done_at')
                        ->whereDoesntHave('castingResultLine.castingResult', function ($rq) {
                            $rq->whereNotNull('cast_date');
                        })
                        ->whereBetween('sand_casting_stage_executions.defect_entered_at', [$targetDate.' 00:00:00', $targetDate.' 23:59:59']);
                })
                ->orWhere(function ($sq) use ($targetDate) {
                    $sq->whereNull('sand_casting_stage_executions.inspection_date')
                        ->whereNull('sand_casting_stage_executions.physical_done_at')
                        ->whereDoesntHave('castingResultLine.castingResult', function ($rq) {
                            $rq->whereNotNull('cast_date');
                        })
                        ->whereNull('sand_casting_stage_executions.defect_entered_at')
                        ->whereBetween('sand_casting_stage_executions.qc_verified_at', [$targetDate.' 00:00:00', $targetDate.' 23:59:59']);
                });
        });
    }

    /**
     * Scope query to match effective defect date between a date range.
     */
    public function scopeWhereEffectiveDefectDateBetween($query, string $startDate, string $endDate)
    {
        if ($startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        return $query->where(function ($sub) use ($startDate, $endDate) {
            $sub->where(function ($dq) use ($startDate, $endDate) {
                $dq->whereBetween('sand_casting_stage_executions.inspection_date', [$startDate, $endDate])
                    ->orWhereBetween('sand_casting_stage_executions.inspection_date', [$startDate.' 00:00:00', $endDate.' 23:59:59']);
            })
                ->orWhere(function ($sq) use ($startDate, $endDate) {
                    $sq->whereNull('sand_casting_stage_executions.inspection_date')
                        ->whereBetween('sand_casting_stage_executions.physical_done_at', [$startDate.' 00:00:00', $endDate.' 23:59:59']);
                })
                ->orWhere(function ($sq) use ($startDate, $endDate) {
                    $sq->whereNull('sand_casting_stage_executions.inspection_date')
                        ->whereNull('sand_casting_stage_executions.physical_done_at')
                        ->whereHas('castingResultLine.castingResult', function ($rq) use ($startDate, $endDate) {
                            $rq->whereBetween('cast_date', [$startDate, $endDate])
                                ->orWhereBetween('cast_date', [$startDate.' 00:00:00', $endDate.' 23:59:59']);
                        });
                })
                ->orWhere(function ($sq) use ($startDate, $endDate) {
                    $sq->whereNull('sand_casting_stage_executions.inspection_date')
                        ->whereNull('sand_casting_stage_executions.physical_done_at')
                        ->whereDoesntHave('castingResultLine.castingResult', function ($rq) {
                            $rq->whereNotNull('cast_date');
                        })
                        ->whereBetween('sand_casting_stage_executions.defect_entered_at', [$startDate.' 00:00:00', $endDate.' 23:59:59']);
                })
                ->orWhere(function ($sq) use ($startDate, $endDate) {
                    $sq->whereNull('sand_casting_stage_executions.inspection_date')
                        ->whereNull('sand_casting_stage_executions.physical_done_at')
                        ->whereDoesntHave('castingResultLine.castingResult', function ($rq) {
                            $rq->whereNotNull('cast_date');
                        })
                        ->whereNull('sand_casting_stage_executions.defect_entered_at')
                        ->whereBetween('sand_casting_stage_executions.qc_verified_at', [$startDate.' 00:00:00', $endDate.' 23:59:59']);
                });
        });
    }
}
