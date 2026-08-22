<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LostWaxPrintExecutionCorrection extends Model
{
    protected $fillable = [
        'print_execution_id',
        'original_qty_good',
        'original_qty_defect',
        'corrected_qty_good',
        'corrected_qty_defect',
        'corrected_by',
        'corrected_at',
        'reason',
    ];

    protected $casts = [
        'original_qty_good' => 'integer',
        'original_qty_defect' => 'integer',
        'corrected_qty_good' => 'integer',
        'corrected_qty_defect' => 'integer',
        'corrected_at' => 'datetime',
    ];

    public function printExecution()
    {
        return $this->belongsTo(LostWaxPrintExecution::class, 'print_execution_id');
    }

    public function corrector()
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }
}
