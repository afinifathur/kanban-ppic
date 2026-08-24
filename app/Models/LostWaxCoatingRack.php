<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LostWaxCoatingRack extends Model
{
    protected $table = 'lost_wax_coating_racks';

    protected $fillable = [
        'rack_number',
        'label',
        'status',
        'notes',
    ];

    protected $casts = [
        'rack_number' => 'integer',
    ];

    public function trees()
    {
        return $this->hasMany(LostWaxTree::class, 'rack_id');
    }
}
