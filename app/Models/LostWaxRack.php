<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LostWaxRack extends Model
{
    protected $fillable = [
        'code',
        'label',
        'location',
        'status',
        'notes',
    ];

    public function mouldingInstances()
    {
        return $this->hasMany(LostWaxMouldingInstance::class, 'rack_id');
    }
}
