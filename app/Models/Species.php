<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Species extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'scientific_name',
        'classification',
        'description',
        'image_path',
        'silhouette_path',
        'habitat',
        'rarity',
        'unlock_points',
        'unlock_hint',
        'is_active_mycodex',
        'archived_at',
    ];

    protected $casts = [
        'unlock_points'     => 'integer',
        'is_active_mycodex' => 'boolean',
        'archived_at'       => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->where('is_active_mycodex', true)->orWhereNull('is_active_mycodex');
        });
    }

    public function scopeArchived($query)
    {
        return $query->where('is_active_mycodex', false);
    }

    protected $appends = [
        'image_url',
        'silhouette_url',
    ];

    public function getImageUrlAttribute(): string
    {
        if (!$this->image_path) {
            return '';
        }

        if (str_starts_with($this->image_path, 'http://') || str_starts_with($this->image_path, 'https://')) {
            return $this->image_path;
        }

        $supabaseUrl = env('AWS_URL');
        if ($supabaseUrl && config('filesystems.disks.public.driver') === 's3') {
            return rtrim($supabaseUrl, '/') . '/' . ltrim($this->image_path, '/');
        }

        return url('/storage/' . ltrim($this->image_path, '/'));
    }

    public function getSilhouetteUrlAttribute(): string
    {
        if (!$this->silhouette_path) {
            return '';
        }

        if (str_starts_with($this->silhouette_path, 'http://') || str_starts_with($this->silhouette_path, 'https://')) {
            return $this->silhouette_path;
        }

        $supabaseUrl = env('AWS_URL');
        if ($supabaseUrl && config('filesystems.disks.public.driver') === 's3') {
            return rtrim($supabaseUrl, '/') . '/' . ltrim($this->silhouette_path, '/');
        }

        return url('/storage/' . ltrim($this->silhouette_path, '/'));
    }

    public function scans()
    {
        return $this->hasMany(Scan::class);
    }
}
