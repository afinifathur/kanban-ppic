<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SandCastingCastingResultCorrection extends Model
{
    protected $fillable = [
        'sand_casting_casting_result_id',
        'sand_casting_casting_result_line_id',
        'target_type',
        'field_name',
        'old_value',
        'new_value',
        'reason',
        'corrected_by',
        'corrected_at',
    ];

    protected $casts = [
        'corrected_at' => 'datetime',
    ];

    public function castingResult()
    {
        return $this->belongsTo(SandCastingCastingResult::class, 'sand_casting_casting_result_id');
    }

    public function castingResultLine()
    {
        return $this->belongsTo(SandCastingCastingResultLine::class, 'sand_casting_casting_result_line_id');
    }

    public function corrector()
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }
}
