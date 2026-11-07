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
        'habitat',
    ];

    protected $appends = [
        'image_url',
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

    public function scans()
    {
        return $this->hasMany(Scan::class);
    }
}
