<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LostWaxCastingResultTree extends Model
{
    use HasFactory;

    protected $fillable = [
        'lost_wax_casting_result_id',
        'lost_wax_tree_id',
        'active_tree_id',
        'barcode',
        'production_code',
        'customer',
        'item_name',
        'quantity_pcs',
        'gross_pcs',
        'defect_pcs',
    ];

    protected $casts = [
        'quantity_pcs' => 'integer',
        'gross_pcs' => 'integer',
        'defect_pcs' => 'integer',
    ];

    /**
     * Parent casting result header.
     */
    public function castingResult()
    {
        return $this->belongsTo(\App\Models\LostWaxCastingResult::class, 'lost_wax_casting_result_id');
    }

    /**
     * Associated physical tree.
     */
    public function tree()
    {
        return $this->belongsTo(\App\Models\LostWaxTree::class, 'lost_wax_tree_id');
    }
}

