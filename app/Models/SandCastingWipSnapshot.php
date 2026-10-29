<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SandCastingWipSnapshot extends Model
{
    use HasFactory;

    public const SOURCE_ACTUAL = 'actual';

    public const SOURCE_RECONSTRUCTED = 'reconstructed';

    public const STATUS_SUCCESS = 'SUCCESS';

    public const STATUS_FAILED = 'FAILED';

    public const WIP_STAGES = [
        'netto',
        'bubut_od',
        'bubut_cnc',
        'bor',
        'qc',
    ];

    public const STAGE_LABELS = [
        'netto' => 'NETTO',
        'bubut_od' => 'Bubut OD',
        'bubut_cnc' => 'Bubut CNC',
        'bor' => 'BOR',
        'qc' => 'QC',
    ];

    protected $table = 'sand_casting_wip_snapshots';

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
        'weight_kg' => 'float',
        'ktr_count' => 'integer',
        'details' => 'array',
    ];

    public function getWeightTonAttribute(): float
    {
        return round($this->weight_kg / 1000, 2);
    }
}
