<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CollectionItem extends Model
{
    protected $fillable = ['collection_folder_id', 'scan_id'];

    public function folder()
    {
        return $this->belongsTo(CollectionFolder::class, 'collection_folder_id');
    }

    public function scan()
    {
        return $this->belongsTo(Scan::class);
    }
}
