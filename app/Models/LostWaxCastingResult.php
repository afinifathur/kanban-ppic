<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LostWaxCastingResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'heat_number',
        'cast_date',
        'furnace',
        'shift',
        'operator_name',
        'total_trees',
        'total_pcs',
        'notes',
        'status',
        'recorded_by',
        'voided_by',
        'voided_at',
        'void_reason',
    ];

    protected $casts = [
        'cast_date' => 'date',
        'voided_at' => 'datetime',
        'total_trees' => 'integer',
        'total_pcs' => 'integer',
    ];

    /**
     * Detail rows (Traveler Cards associated with this casting result).
     */
    public function trees()
    {
        return $this->hasMany(\App\Models\LostWaxCastingResultTree::class, 'lost_wax_casting_result_id');
    }

    /**
     * Issued Kitir Produksi records associated with this casting result.
     */
    public function kitirs()
    {
        return $this->hasMany(\App\Models\LostWaxKitir::class, 'lost_wax_casting_result_id');
    }

    /**
     * User who recorded this casting result.
     */
    public function recorder()
    {
        return $this->belongsTo(\App\Models\User::class, 'recorded_by');
    }

    /**
     * User who voided/cancelled this casting result.
     */
    public function voider()
    {
        return $this->belongsTo(\App\Models\User::class, 'voided_by');
    }

    /**
     * Check if the casting result is cancelled.
     */
    public function getIsCancelledAttribute(): bool
    {
        return $this->status === 'CANCELLED';
    }

    /**
     * Check if the casting result is completed/active.
     */
    public function getIsCompletedAttribute(): bool
    {
        return $this->status === 'COMPLETED';
    }

    /**
     * Generate transaction number based on ID and cast date.
     */
    public function getTransactionNumberAttribute(): string
    {
        $datePart = $this->cast_date ? $this->cast_date->format('Ymd') : date('Ymd');

        return sprintf('LW-COR-%s-%04d', $datePart, $this->id);
    }

    /**
     * Compute Kitir status summary (issued count and printed count against unique product codes).
     * Denominator is the count of unique production_code groups in this casting result.
     *
     * @return array{total: int, issued: int, printed: int, is_fully_issued: bool, is_fully_printed: bool}
     */
    public function getKitirStatusAttribute(): array
    {
        $trees = $this->relationLoaded('trees') ? $this->trees : $this->trees()->get();
        $kitirs = $this->relationLoaded('kitirs') ? $this->kitirs : $this->kitirs()->get();

        $productCodes = $trees
            ->pluck('production_code')
            ->map(fn ($c) => trim((string) $c))
            ->filter(fn ($c) => $c !== '' && $c !== '-')
            ->unique()
            ->values();

        $totalProducts = $productCodes->count();

        if ($totalProducts === 0) {
            return [
                'total' => 0,
                'issued' => 0,
                'printed' => 0,
                'is_fully_issued' => false,
                'is_fully_printed' => false,
            ];
        }

        $kitirsByCode = $kitirs->keyBy(fn ($k) => trim((string) $k->production_code));

        $issued = 0;
        $printed = 0;

        foreach ($productCodes as $code) {
            $kitir = $kitirsByCode->get($code);
            if ($kitir !== null) {
                $issued++;
                if ((int) $kitir->print_count > 0) {
                    $printed++;
                }
            }
        }

        return [
            'total' => $totalProducts,
            'issued' => $issued,
            'printed' => $printed,
            'is_fully_issued' => $issued === $totalProducts,
            'is_fully_printed' => $printed === $totalProducts,
        ];
    }
}
