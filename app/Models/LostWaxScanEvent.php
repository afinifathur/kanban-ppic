<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class LostWaxScanEvent extends Model
{
    public const SOURCE_PHYSICAL_SCAN = 'physical_scan';

    public const SOURCE_HISTORICAL_RECONCILIATION = 'historical_reconciliation';

    protected $fillable = [
        'tree_id',
        'barcode',
        'stage',
        'scanned_qty',
        'scanned_at',
        'operator_id',
        'result',
        'source',
        'anomaly_reason',
        'notes',
        'aging_minutes',
        'aging_status',
    ];

    protected $casts = [
        'scanned_qty' => 'integer',
        'scanned_at' => 'datetime',
        'aging_minutes' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $event) {
            if ($event->isDirty('scanned_qty')) {
                throw new LogicException('Kolom scanned_qty pada lost_wax_scan_events bersifat immutable dan tidak boleh diubah atau di-backfill.');
            }
        });
    }

    public function getIsReconciliationAttribute(): bool
    {
        return $this->source === self::SOURCE_HISTORICAL_RECONCILIATION;
    }

    public function tree()
    {
        return $this->belongsTo(LostWaxTree::class, 'tree_id');
    }

    public function operator()
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function void()
    {
        return $this->hasOne(LostWaxScanEventVoid::class, 'scan_event_id');
    }

    public function getIsVoidedAttribute(): bool
    {
        return $this->void()->exists();
    }

    public function getIsAnomalyAttribute(): bool
    {
        return $this->result === 'rejected';
    }

    public function getStageLabelAttribute(): string
    {
        $stages = config('lost_wax.stages', []);

        return $stages[$this->stage] ?? ucfirst(str_replace('_', ' ', (string) $this->stage));
    }

    public function getAgingLabelAttribute(): ?string
    {
        if ($this->aging_minutes === null) {
            return null;
        }

        $hours = intdiv(abs($this->aging_minutes), 60);
        $mins = abs($this->aging_minutes) % 60;

        $parts = [];
        if ($hours > 0) {
            $parts[] = $hours.' jam';
        }
        $parts[] = $mins.' menit';

        return implode(' ', $parts);
    }
}
