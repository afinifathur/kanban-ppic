<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LostWaxScanEventVoid extends Model
{
    protected $table = 'lost_wax_scan_event_voids';

    protected $fillable = [
        'scan_event_id',
        'voided_by',
        'voided_at',
        'void_reason',
    ];

    protected $casts = [
        'voided_at' => 'datetime',
    ];

    public function scanEvent()
    {
        return $this->belongsTo(LostWaxScanEvent::class, 'scan_event_id');
    }

    public function voidedByUser()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
