<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PrintJob extends Model
{
    protected $fillable = [
        'job_uuid',
        'printer_name',
        'payload_tspl',
        'payload_hash',
        'copies',
        'status',
        'claimed_by_machine',
        'claimed_at',
        'printed_at',
        'failed_at',
        'error_message',
        'retry_count',
        'created_by',
        'template_type',
    ];

    protected $casts = [
        'claimed_at' => 'datetime',
        'printed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->job_uuid)) {
                $model->job_uuid = (string) Str::uuid();
            }
        });
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    public function isPrinted(): bool
    {
        return $this->status === 'printed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
