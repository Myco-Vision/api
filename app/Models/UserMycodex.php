<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserMycodex extends Model
{
    use HasFactory;

    protected $table = 'user_mycodex';

    protected $fillable = [
        'user_id',
        'species_name',
        'first_scanned_at',
        'scan_count',
        'cover_image_path',
    ];

    protected $casts = [
        'first_scanned_at' => 'datetime',
        'scan_count'       => 'integer',
    ];

    protected $appends = [
        'cover_image_url',
    ];

    public function getCoverImageUrlAttribute(): string
    {
        if (!$this->cover_image_path) {
            return '';
        }

        if (str_starts_with($this->cover_image_path, 'http://') || str_starts_with($this->cover_image_path, 'https://')) {
            return $this->cover_image_path;
        }

        $supabaseUrl = config('filesystems.disks.public.url') ?: env('AWS_URL');
        if ($supabaseUrl && config('filesystems.disks.public.driver') === 's3') {
            return rtrim($supabaseUrl, '/') . '/' . ltrim($this->cover_image_path, '/');
        }

        return url('/storage/' . ltrim($this->cover_image_path, '/'));
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
