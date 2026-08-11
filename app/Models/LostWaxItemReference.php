<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LostWaxItemReference extends Model
{
    protected $fillable = [
        'master_source',
        'master_item_key',
        'item_code_snapshot',
        'item_name_snapshot',
        'aisi_snapshot',
        'standard_snapshot',
        'unit_weight_snapshot',
        'status_snapshot',
        'last_synced_at',
    ];

    protected $casts = [
        'unit_weight_snapshot' => 'decimal:3',
        'last_synced_at' => 'datetime',
    ];

    public function mouldingFamilies()
    {
        return $this->hasMany(LostWaxMouldingFamily::class, 'item_reference_id');
    }

    public function workOrders()
    {
        return $this->hasMany(LostWaxWorkOrder::class, 'item_reference_id');
    }
}
