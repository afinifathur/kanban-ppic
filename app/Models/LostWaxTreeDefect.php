<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LostWaxTreeDefect extends Model
{
    protected $fillable = [
        'lost_wax_tree_id',
        'stage',
        'defect_qty',
        'defect_reason',
        'notes',
        'recorded_by',
        'occurred_at',
    ];

    protected $casts = [
        'defect_qty' => 'integer',
        'occurred_at' => 'datetime',
    ];

    public function tree()
    {
        return $this->belongsTo(LostWaxTree::class, 'lost_wax_tree_id');
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
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
