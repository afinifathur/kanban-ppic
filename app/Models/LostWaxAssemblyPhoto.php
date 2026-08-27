<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LostWaxAssemblyPhoto extends Model
{
    use HasFactory;

    protected $table = 'lost_wax_assembly_photos';

    protected $fillable = [
        'product_code',
        'product_name',
        'version',
        'front_image_path',
        'side_image_path',
        'is_current',
        'created_by',
        'notes',
    ];

    protected $casts = [
        'version' => 'integer',
        'is_current' => 'boolean',
    ];

    public function uploader()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getFrontImageUrlAttribute(): ?string
    {
        if (! $this->front_image_path) {
            return null;
        }

        return asset('storage/'.$this->front_image_path);
    }

    public function getSideImageUrlAttribute(): ?string
    {
        if (! $this->side_image_path) {
            return null;
        }

        return asset('storage/'.$this->side_image_path);
    }
}
