<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LostWaxPrintExecution extends Model
{
    protected $fillable = [
        'lost_wax_print_order_line_id',
        'execution_date',
        'qty_gross_output',
        'qty_good',
        'qty_defect',
        'status',
        'notes',
        'recorded_by',
        'recorded_at',
        'finalized_by',
        'finalized_at',
    ];

    protected $casts = [
        'execution_date' => 'date',
        'qty_gross_output' => 'integer',
        'qty_good' => 'integer',
        'qty_defect' => 'integer',
        'recorded_at' => 'datetime',
        'finalized_at' => 'datetime',
    ];

    public function getGrossOutputAttribute(): int
    {
        return $this->qty_gross_output !== null
            ? (int) $this->qty_gross_output
            : (int) ($this->qty_good + $this->qty_defect);
    }

    public function printOrderLine()
    {
        return $this->belongsTo(LostWaxPrintOrderLine::class, 'lost_wax_print_order_line_id');
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function finalizer()
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function correction()
    {
        return $this->hasOne(LostWaxPrintExecutionCorrection::class, 'print_execution_id');
    }
}
