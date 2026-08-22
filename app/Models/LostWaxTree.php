<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LostWaxTree extends Model
{
    protected $fillable = [
        'work_order_id',
        'work_order_plan_id',
        'lost_wax_print_order_line_id',
        'rangkai_execution_id',
        'barcode',
        'tree_number',
        'quantity',
        'status',
        'current_stage',
        'last_scan_at',
        'production_date',
        'family_code',
        'daily_sequence',
        'require_layer_7',
    ];

    protected $casts = [
        'tree_number' => 'integer',
        'quantity' => 'integer',
        'daily_sequence' => 'integer',
        'production_date' => 'date',
        'last_scan_at' => 'datetime',
        'rangkai_execution_id' => 'integer',
        'require_layer_7' => 'boolean',
    ];

    public function workOrder()
    {
        return $this->belongsTo(LostWaxWorkOrder::class, 'work_order_id');
    }

    public function plan()
    {
        return $this->belongsTo(LostWaxWorkOrderPlan::class, 'work_order_plan_id');
    }

    public function printOrderLine()
    {
        return $this->belongsTo(LostWaxPrintOrderLine::class, 'lost_wax_print_order_line_id');
    }

    public function rangkaiExecution()
    {
        return $this->belongsTo(LostWaxRangkaiExecution::class, 'rangkai_execution_id');
    }

    public function getHumanBarcodeAttribute(): string
    {
        $barcode = $this->barcode;

        return substr($barcode, 0, 1).' '.
               substr($barcode, 1, 2).'-'.
               substr($barcode, 3, 2).'-'.
               substr($barcode, 5, 2).' '.
               substr($barcode, 7, 4);
    }

    public function getIsCorrectableAttribute(): bool
    {
        return $this->work_order_id !== null && in_array($this->status, ['generated', 'ready_for_coating']);
    }

    public function scanEvents()
    {
        return $this->hasMany(LostWaxScanEvent::class, 'tree_id');
    }

    // Compatibility Helpers for Explicit Traceability
    public function getSourceTypeAttribute(): string
    {
        if ($this->work_order_id) {
            return 'work_order';
        }
        if ($this->lost_wax_print_order_line_id) {
            return 'print_order_line';
        }

        return 'unknown';
    }

    public function getSourceCode(): ?string
    {
        if ($this->lost_wax_print_order_line_id) {
            return $this->printOrderLine?->code;
        }
        if ($this->work_order_id) {
            return $this->workOrder?->et_code;
        }

        return null;
    }

    public function getSourcePrintOrderNumber(): ?string
    {
        if ($this->lost_wax_print_order_line_id) {
            return $this->printOrderLine?->printOrder?->print_order_number;
        }

        return null;
    }

    public function getSourceCustomer(): ?string
    {
        if ($this->lost_wax_print_order_line_id) {
            return $this->printOrderLine?->customer;
        }
        if ($this->work_order_id) {
            return $this->workOrder?->customer_name;
        }

        return null;
    }

    public function getSourceProduct(): ?string
    {
        if ($this->lost_wax_print_order_line_id) {
            return $this->printOrderLine?->item_name;
        }
        if ($this->work_order_id) {
            return $this->workOrder?->itemReference?->item_name_snapshot;
        }

        return null;
    }

    public function getSourceItemCode(): ?string
    {
        if ($this->lost_wax_print_order_line_id) {
            return $this->printOrderLine?->code; // code is the snapshot item/cust code (e.g. AB61)
        }
        if ($this->work_order_id) {
            return $this->workOrder?->itemReference?->item_code_snapshot;
        }

        return null;
    }

    public function getSourceAisi(): ?string
    {
        if ($this->lost_wax_print_order_line_id) {
            return $this->printOrderLine?->aisi;
        }
        if ($this->work_order_id) {
            return $this->workOrder?->itemReference?->aisi_snapshot;
        }

        return null;
    }

    public function getSourceSize(): ?string
    {
        if ($this->lost_wax_print_order_line_id) {
            return $this->printOrderLine?->size;
        }

        return null;
    }

    public function getCurrentStageLabelAttribute(): string
    {
        if (! $this->current_stage) {
            return 'Sebelum Lapisan 1';
        }

        $stages = config('lost_wax.stages', []);

        return $stages[$this->current_stage] ?? ucfirst(str_replace('_', ' ', $this->current_stage));
    }

    public function getRequireLayer7Attribute(): bool
    {
        if (array_key_exists('require_layer_7', $this->attributes) && (bool) $this->attributes['require_layer_7'] === true) {
            return true;
        }

        if ($this->work_order_id !== null) {
            return (bool) ($this->workOrder?->require_layer_7 ?? false);
        }

        return false;
    }

    public function hasMoreStages(): bool
    {
        $next = $this->nextStage();

        return $next !== null;
    }

    public function nextStage(): ?string
    {
        $stages = array_keys(config('lost_wax.stages', []));

        if (! $this->current_stage) {
            return $stages[0] ?? null;
        }

        $currentIdx = array_search($this->current_stage, $stages);

        if ($currentIdx === false) {
            return null;
        }

        $nextIdx = $currentIdx + 1;

        if ($nextIdx >= count($stages)) {
            return null;
        }

        $nextStage = $stages[$nextIdx];

        if ($this->current_stage === 'layer_6' && $nextStage === 'layer_7' && ! $this->require_layer_7) {
            $nextStage = $stages[$nextIdx + 1] ?? null;
        }

        if ($nextStage === 'oven') {
            return null;
        }

        return $nextStage;
    }
}
