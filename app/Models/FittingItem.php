<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FittingItem extends Model
{
    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const STAGE_NETTO_PF = 'netto_pf';

    public const STAGE_KIMIA = 'kimia';

    public const STAGE_MARKING = 'marking';

    public const STAGE_BUBUT_CNC_PF = 'bubut_cnc_pf';

    public const STAGE_QC_PF = 'qc_pf';

    public const STAGE_GUDANG_JADI_PF = 'gudang_jadi_pf';

    public const STAGE_COMPLETED = 'completed';

    public const STAGES = [
        self::STAGE_NETTO_PF,
        self::STAGE_KIMIA,
        self::STAGE_MARKING,
        self::STAGE_BUBUT_CNC_PF,
        self::STAGE_QC_PF,
        self::STAGE_GUDANG_JADI_PF,
    ];

    /**
     * Resolve the next stage in strict linear Fitting sequence (no rework).
     * Returns STAGE_COMPLETED after gudang_jadi_pf, or null if invalid/already completed.
     */
    public static function nextStageFor(?string $currentStage): ?string
    {
        if ($currentStage === null) {
            return null;
        }

        $idx = array_search($currentStage, self::STAGES, true);
        if ($idx === false) {
            return null;
        }

        return self::STAGES[$idx + 1] ?? self::STAGE_COMPLETED;
    }

    /**
     * Resolve the predecessor stage in strict linear Fitting sequence.
     */
    public static function previousStageFor(?string $stage): ?string
    {
        if ($stage === null) {
            return null;
        }

        $idx = array_search($stage, self::STAGES, true);
        if ($idx === false || $idx === 0) {
            return null;
        }

        return self::STAGES[$idx - 1];
    }

    protected $fillable = [
        'fitting_transfer_id',
        'lost_wax_kitir_id',
        'active_kitir_id',
        'lost_wax_casting_result_id',
        'production_plan_id',
        'traveler_number',
        'heat_number',
        'cast_date',
        'production_code',
        'item_code',
        'item_name',
        'customer',
        'line_number',
        'tree_count',
        'qty_transferred',
        'unit_weight_kg',
        'total_weight_kg',
        'is_multi_allocation',
        'current_stage',
        'queue_position',
        'is_urgent',
        'urgent_set_at',
        'urgent_set_by',
        'status',
        'cancelled_by',
        'cancelled_at',
        'cancel_reason',
        'notes',
    ];

    protected $casts = [
        'cast_date' => 'date',
        'line_number' => 'integer',
        'tree_count' => 'integer',
        'qty_transferred' => 'integer',
        'unit_weight_kg' => 'decimal:4',
        'total_weight_kg' => 'decimal:2',
        'is_multi_allocation' => 'boolean',
        'queue_position' => 'integer',
        'is_urgent' => 'boolean',
        'urgent_set_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(\App\Models\FittingTransfer::class, 'fitting_transfer_id');
    }

    public function lostWaxKitir(): BelongsTo
    {
        return $this->belongsTo(\App\Models\LostWaxKitir::class, 'lost_wax_kitir_id');
    }

    public function lostWaxCastingResult(): BelongsTo
    {
        return $this->belongsTo(\App\Models\LostWaxCastingResult::class, 'lost_wax_casting_result_id');
    }

    public function productionPlan(): BelongsTo
    {
        return $this->belongsTo(\App\Models\ProductionPlan::class, 'production_plan_id');
    }

    public function urgentSetBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'urgent_set_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'cancelled_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(\App\Models\FittingItemAllocation::class, 'fitting_item_id');
    }

    public function stageExecutions(): HasMany
    {
        return $this->hasMany(\App\Models\FittingStageExecution::class, 'fitting_item_id');
    }

    public const VALID_LINE_NUMBERS = [1, 2, 3, 4];

    public const LINE_LABELS = [
        1 => 'Line 1 — Fitting biasa',
        2 => 'Line 2 — Union',
        3 => 'Line 3 — Ball Valve',
        4 => 'Line 4 — BW',
    ];

    public static function lineLabelFor(?int $lineNumber): string
    {
        if ($lineNumber === null) {
            return 'Unassigned';
        }

        return self::LINE_LABELS[$lineNumber] ?? "Line {$lineNumber}";
    }

    /**
     * Phase 2 & 4.1 Operational Contract:
     * - Mutual exclusion between `fitting_items.active_kitir_id` and `fitting_kitir_decisions.active_kitir_id`
     *   MUST be enforced inside `DB::transaction()` using `LostWaxKitir::whereIn('id', $sortedIds)->lockForUpdate()`.
     * - When cancelling a transfer (allowed ONLY before any `fitting_stage_executions`), the service MUST set:
     *   `status = STATUS_CANCELLED`, `active_kitir_id = null`, AND `current_stage = null`.
     * - When an item completes (`current_stage = STAGE_COMPLETED`), `status` MUST remain `STATUS_ACTIVE`
     *   and `active_kitir_id` MUST remain `lost_wax_kitir_id` to permanently block re-transfer.
     * - `fitting_items.line_number` is the Final Operational Line (1..4) selected by PPIC at transfer time
     *   (never auto-inferred from item name/size). Legacy `null` rows remain Unassigned without auto-mutation.
     */
    public static function normalizeLineNumber(mixed $lineNumber): ?int
    {
        if ($lineNumber === null || $lineNumber === '' || ! is_numeric($lineNumber)) {
            return null;
        }

        $parsed = (int) $lineNumber;

        return in_array($parsed, self::VALID_LINE_NUMBERS, true) ? $parsed : null;
    }

    public function hasPhysicalExecution(): bool
    {
        if ($this->relationLoaded('stageExecutions')) {
            return $this->stageExecutions->isNotEmpty();
        }

        return $this->stageExecutions()->exists();
    }

    public function getIsLineAssignedAttribute(): bool
    {
        return $this->line_number !== null && in_array($this->line_number, [1, 2, 3, 4], true);
    }
}
