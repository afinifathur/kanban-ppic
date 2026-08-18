<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LostWaxPrintOrder extends Model
{
    protected $fillable = [
        'print_order_number',
        'scheduled_date',
        'status',
        'created_by',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines()
    {
        return $this->hasMany(LostWaxPrintOrderLine::class, 'lost_wax_print_order_id');
    }

    public function hasRecordedOutcomes(): bool
    {
        return $this->lines()->whereNotNull('qty_actual_good')->exists();
    }
}
