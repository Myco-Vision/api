<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CollectionFolder extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'name', 'color', 'allow_ai_training'];

    protected $casts = [
        'allow_ai_training' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scans()
    {
        return $this->belongsToMany(Scan::class, 'collection_items')
            ->withTimestamps()
            ->latest('collection_items.created_at');
    }

    public function items()
    {
        return $this->hasMany(CollectionItem::class);
    }
}
