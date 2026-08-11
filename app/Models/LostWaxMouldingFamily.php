<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LostWaxMouldingFamily extends Model
{
    protected $fillable = [
        'item_reference_id',
        'family_code',
        'name',
        'description',
        'status',
        'notes',
    ];

    public function itemReference()
    {
        return $this->belongsTo(LostWaxItemReference::class, 'item_reference_id');
    }

    public function instances()
    {
        return $this->hasMany(LostWaxMouldingInstance::class, 'moulding_family_id');
    }
}
