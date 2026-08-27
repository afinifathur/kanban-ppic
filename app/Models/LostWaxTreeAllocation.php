<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LostWaxTreeAllocation extends Model
{
    protected $table = 'lost_wax_tree_allocations';

    protected $fillable = [
        'lost_wax_tree_id',
        'lost_wax_print_order_line_id',
        'allocated_qty',
    ];

    protected $casts = [
        'allocated_qty' => 'integer',
    ];

    public function tree()
    {
        return $this->belongsTo(LostWaxTree::class, 'lost_wax_tree_id');
    }

    public function printOrderLine()
    {
        return $this->belongsTo(LostWaxPrintOrderLine::class, 'lost_wax_print_order_line_id');
    }
}
