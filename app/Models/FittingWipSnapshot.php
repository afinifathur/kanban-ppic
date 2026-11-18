<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FittingWipSnapshot extends Model
{
    public const SOURCE_ACTUAL = 'actual';

    public const SOURCE_RECONSTRUCTED = 'reconstructed';

    public const STATUS_SUCCESS = 'SUCCESS';

    public const STATUS_FAILED = 'FAILED';

    public const WIP_STAGES = [
        FittingItem::STAGE_NETTO_PF,
        FittingItem::STAGE_KIMIA,
        FittingItem::STAGE_MARKING,
        FittingItem::STAGE_BUBUT_CNC_PF,
        FittingItem::STAGE_QC_PF,
    ];

    protected $fillable = [
        'snapshot_date',
        'scheduled_cutoff_at',
        'captured_at',
        'stage',
        'product_scope',
        'qty_pcs',
        'weight_kg',
        'ktr_count',
        'source_type',
        'status',
        'details',
    ];

    protected $casts = [
        'snapshot_date' => 'date:Y-m-d',
        'scheduled_cutoff_at' => 'datetime',
        'captured_at' => 'datetime',
        'qty_pcs' => 'integer',
        'weight_kg' => 'decimal:4',
        'ktr_count' => 'integer',
        'details' => 'array',
    ];
}
