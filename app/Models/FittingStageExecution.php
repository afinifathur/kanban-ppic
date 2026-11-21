<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FittingStageExecution extends Model
{
    public const STATUS_READY = 'READY';

    public const STATUS_WAITING_DEFECT = 'WAITING_DEFECT';

    public const STATUS_NO_DEFECT = 'NO_DEFECT';

    public const STATUS_WAITING_QC = 'WAITING_QC';

    public const STATUS_REOPENED_FOR_CORRECTION = 'REOPENED_FOR_CORRECTION';

    public const STATUS_CONFIRMED = 'CONFIRMED';

    public const CHECKPOINT_NETTO = 'PF_NETTO';

    public const CHECKPOINT_KIMIA = 'PF_KIMIA';

    public const CHECKPOINT_MARKING = 'PF_MARKING';

    public const CHECKPOINT_CNC = 'PF_CNC';

    public const CHECKPOINT_QC = 'PF_QC';

    public const CHECKPOINT_GUDANG_JADI = 'PF_GUDANG_JADI';

    protected $fillable = [
        'fitting_item_id',
        'stage',
        'checkpoint_code',
        'input_qty',
        'defect_qty',
        'good_qty',
        'status',
        'operator_id',
        'executed_at',
        'physical_done_at',
        'inspection_date',
        'defect_entered_at',
        'defect_entered_by',
        'qc_verified_at',
        'qc_verified_by',
        'is_auto_nihil',
        'auto_nihil_at',
        'notes',
    ];

    protected $casts = [
        'input_qty' => 'integer',
        'defect_qty' => 'integer',
        'good_qty' => 'integer',
        'executed_at' => 'datetime',
        'physical_done_at' => 'datetime',
        'inspection_date' => 'date',
        'defect_entered_at' => 'datetime',
        'qc_verified_at' => 'datetime',
        'is_auto_nihil' => 'boolean',
        'auto_nihil_at' => 'datetime',
    ];

    public function fittingItem(): BelongsTo
    {
        return $this->belongsTo(\App\Models\FittingItem::class, 'fitting_item_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'operator_id');
    }

    public function defectEnteredBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'defect_entered_by');
    }

    public function qcVerifiedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'qc_verified_by');
    }

    public function defects(): HasMany
    {
        return $this->hasMany(\App\Models\FittingStageExecutionDefect::class, 'fitting_stage_execution_id');
    }

    public function defectLogs(): HasMany
    {
        return $this->hasMany(\App\Models\FittingStageExecutionDefectLog::class, 'fitting_stage_execution_id')
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc');
    }

    /**
     * Resolve effective defect event date following business hierarchy:
     * 1. inspection_date
     * 2. physical_done_at (fallback if inspection_date is null)
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

        return null;
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
                $dq->whereNotNull('fitting_stage_executions.inspection_date')
                    ->where(function ($idq) use ($startDate, $endDate) {
                        $idq->whereBetween('fitting_stage_executions.inspection_date', [$startDate, $endDate])
                            ->orWhereBetween('fitting_stage_executions.inspection_date', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
                    });
            })->orWhere(function ($sq) use ($startDate, $endDate) {
                $sq->whereNull('fitting_stage_executions.inspection_date')
                    ->whereNotNull('fitting_stage_executions.physical_done_at')
                    ->whereBetween('fitting_stage_executions.physical_done_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59']);
            });
        });
    }
}
