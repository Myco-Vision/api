<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Scan extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'species_id',
        'image_path',
        'result_name',
        'result_classification',
        'confidence_level',
        'latitude',
        'longitude',
        'location_name',
        'location_source',
        'notes',
        'retrained_at',
    ];

    protected $casts = [
        'retrained_at' => 'datetime',
    ];

    protected $appends = [
        'image_url',
    ];

    public function getImageUrlAttribute(): string
    {
        if (!$this->image_path) {
            return '';
        }

        // If it's already a full URL
        if (str_starts_with($this->image_path, 'http://') || str_starts_with($this->image_path, 'https://')) {
            return $this->image_path;
        }

        // Direct Supabase storage URL when available
        $supabaseUrl = config('filesystems.disks.public.url') ?: env('AWS_URL');
        if ($supabaseUrl && config('filesystems.disks.public.driver') === 's3') {
            return rtrim($supabaseUrl, '/') . '/' . ltrim($this->image_path, '/');
        }

        return url('/storage/' . ltrim($this->image_path, '/'));
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function species()
    {
        return $this->belongsTo(Species::class);
    }
}
