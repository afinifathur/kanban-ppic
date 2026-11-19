<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class LostWaxTreeDefect extends Model
{
    public const CONTEXT_PRE_SIAP_COR = 'pre_siap_cor';

    public const CONTEXT_POST_SIAP_COR = 'post_siap_cor';

    public const VALID_CONTEXTS = [
        self::CONTEXT_PRE_SIAP_COR,
        self::CONTEXT_POST_SIAP_COR,
    ];

    protected $fillable = [
        'lost_wax_tree_id',
        'stage',
        'defect_context',
        'is_late_entry',
        'scan_event_id',
        'defect_qty',
        'defect_reason',
        'notes',
        'recorded_by',
        'occurred_at',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    protected $casts = [
        'defect_qty' => 'integer',
        'is_late_entry' => 'boolean',
        'scan_event_id' => 'integer',
        'occurred_at' => 'datetime',
        'voided_at' => 'datetime',
        'voided_by' => 'integer',
    ];

    protected static function booted(): void
    {
        static::deleting(function () {
            throw new LogicException('Record defect Lost Wax tidak boleh dihapus permanen. Gunakan mekanisme soft-void dengan audit trail.');
        });

        static::updating(function (self $defect) {
            $immutableColumns = [
                'lost_wax_tree_id',
                'stage',
                'defect_context',
                'is_late_entry',
                'scan_event_id',
                'defect_qty',
                'defect_reason',
                'notes',
                'recorded_by',
                'occurred_at',
            ];

            foreach ($immutableColumns as $col) {
                if ($defect->isDirty($col)) {
                    throw new LogicException("Kolom histori defect '{$col}' bersifat immutable. Gunakan soft-void dengan audit trail untuk koreksi.");
                }
            }

            if ($defect->getOriginal('voided_at') !== null) {
                throw new LogicException('Record defect yang sudah berstatus VOIDED tidak dapat diubah kembali.');
            }

            if ($defect->isDirty(['voided_at', 'voided_by', 'void_reason'])) {
                if ($defect->voided_at === null || $defect->voided_by === null || trim((string) $defect->void_reason) === '') {
                    throw new LogicException('Soft-void defect wajib menyertakan voided_at, voided_by, dan void_reason.');
                }
            }
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    public function tree()
    {
        return $this->belongsTo(LostWaxTree::class, 'lost_wax_tree_id');
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function voidedBy()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function scanEvent()
    {
        return $this->belongsTo(LostWaxScanEvent::class, 'scan_event_id');
    }

    public function getIsVoidedAttribute(): bool
    {
        return $this->voided_at !== null;
    }

    public function getIsPostSiapCorAttribute(): bool
    {
        return ($this->defect_context ?? self::CONTEXT_PRE_SIAP_COR) === self::CONTEXT_POST_SIAP_COR;
    }

    public function getContextLabelAttribute(): string
    {
        return $this->is_post_siap_cor ? 'Pasca-Siap Cor' : 'Pra-Siap Cor';
    }

    public function getEffectiveAtWibAttribute(): ?Carbon
    {
        $ts = $this->occurred_at ?? $this->created_at;

        return $ts ? $ts->copy()->setTimezone('Asia/Jakarta') : null;
    }

    public function getEffectiveDateWibAttribute(): ?string
    {
        return $this->effective_at_wib?->format('Y-m-d');
    }

    public function getStageLabelAttribute(): string
    {
        $stages = config('lost_wax.stages', []);

        if ($this->stage === 'assembly') {
            return 'Rangkai';
        }

        return $stages[$this->stage] ?? ucfirst(str_replace('_', ' ', (string) $this->stage));
    }
}
