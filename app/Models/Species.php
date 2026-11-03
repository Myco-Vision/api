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

    public function scans()
    {
        return $this->hasMany(Scan::class);
    }
}
