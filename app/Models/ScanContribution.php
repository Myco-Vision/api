<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScanContribution extends Model
{
    use HasFactory;

    protected $fillable = [
        'scan_id',
        'user_id',
        'image_path',
        'predicted_species',
        'verified_species',
        'confidence_score',
        'annotation_status',
        'points_awarded',
        'is_new_species_for_user',
    ];

    protected $casts = [
        'confidence_score'        => 'float',
        'points_awarded'          => 'integer',
        'is_new_species_for_user' => 'boolean',
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

        $supabaseUrl = config('filesystems.disks.public.url') ?: env('AWS_URL');
        if ($supabaseUrl && config('filesystems.disks.public.driver') === 's3') {
            return rtrim($supabaseUrl, '/') . '/' . ltrim($this->image_path, '/');
        }

        return url('/storage/' . ltrim($this->image_path, '/'));
    }

    public function scan()
    {
        return $this->belongsTo(Scan::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

