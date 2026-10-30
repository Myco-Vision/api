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

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function species()
    {
        return $this->belongsTo(Species::class);
    }
}
