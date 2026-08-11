<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LostWaxMouldingInstance extends Model
{
    protected $fillable = [
        'moulding_family_id',
        'instance_code',
        'label',
        'rack_id',
        'status',
        'notes',
    ];

    public function family()
    {
        return $this->belongsTo(LostWaxMouldingFamily::class, 'moulding_family_id');
    }

    public function rack()
    {
        return $this->belongsTo(LostWaxRack::class, 'rack_id');
    }
}
