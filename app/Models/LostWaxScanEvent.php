<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LostWaxScanEvent extends Model
{
    protected $fillable = [
        'tree_id',
        'barcode',
        'stage',
        'scanned_at',
        'operator_id',
        'result',
        'anomaly_reason',
        'aging_minutes',
        'aging_status',
    ];

    protected $casts = [
        'scanned_at' => 'datetime',
        'aging_minutes' => 'integer',
    ];

    public function tree()
    {
        return $this->belongsTo(LostWaxTree::class, 'tree_id');
    }

    public function operator()
    {
        return $this->belongsTo(User::class, 'operator_id');
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
