<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LostWaxHistoricalCutoffTree extends Model
{
    use HasFactory;

    protected $fillable = [
        'lost_wax_historical_cutoff_id',
        'lost_wax_tree_id',
        'active_tree_id',
        'barcode',
        'tree_number',
        'work_order_id',
        'lost_wax_print_order_line_id',
        'production_code',
        'customer',
        'item_name',
        'previous_stage',
        'previous_status',
        'quantity',
        'defect_quantity',
        'usable_quantity',
        'siap_cor_date',
        'siap_cor_date_source',
        'cor_date',
        'cor_date_source',
        'recorded_at',
    ];

    protected $casts = [
        'tree_number' => 'integer',
        'quantity' => 'integer',
        'defect_quantity' => 'integer',
        'usable_quantity' => 'integer',
        'siap_cor_date' => 'datetime',
        'cor_date' => 'date',
        'recorded_at' => 'datetime',
    ];

    /**
     * Parent cutoff header transaction.
     */
    public function cutoff()
    {
        return $this->belongsTo(\App\Models\LostWaxHistoricalCutoff::class, 'lost_wax_historical_cutoff_id');
    }

    /**
     * Associated physical tree.
     */
    public function tree()
    {
        return $this->belongsTo(\App\Models\LostWaxTree::class, 'lost_wax_tree_id');
    }

    /**
     * Legacy work order relation (if legacy).
     */
    public function workOrder()
    {
        return $this->belongsTo(\App\Models\LostWaxWorkOrder::class, 'work_order_id');
    }

    /**
     * New flow print order line relation (if new flow).
     */
    public function printOrderLine()
    {
        return $this->belongsTo(\App\Models\LostWaxPrintOrderLine::class, 'lost_wax_print_order_line_id');
    }
}

