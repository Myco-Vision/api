<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RetrainRun extends Model
{
    protected $fillable = [
        'triggered_by',
        'status',
        'new_edible',
        'new_poisonous',
        'total_edible',
        'total_poisonous',
        'total_not_mushroom',
        'total_images',
        'train_accuracy',
        'val_accuracy',
        'error',
        'scan_ids',
        'scans_marked_retrained',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'new_edible'             => 'integer',
        'new_poisonous'          => 'integer',
        'total_edible'           => 'integer',
        'total_poisonous'        => 'integer',
        'total_not_mushroom'     => 'integer',
        'total_images'           => 'integer',
        'train_accuracy'         => 'float',
        'val_accuracy'           => 'float',
        'scan_ids'               => 'array',
        'scans_marked_retrained' => 'boolean',
        'started_at'             => 'datetime',
        'finished_at'            => 'datetime',
    ];

    public function triggeredBy()
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    /**
     * Format this run to match the history payload expected by the frontend.
     */
    public function toHistoryArray(): array
    {
        $durationSeconds = null;
        if ($this->started_at && $this->finished_at) {
            $durationSeconds = max(0, $this->finished_at->diffInSeconds($this->started_at));
        }

        return [
            'id'                     => $this->id,
            'started_at'             => $this->started_at?->toIso8601ZuluString() ?? $this->created_at?->toIso8601ZuluString(),
            'finished_at'            => $this->finished_at?->toIso8601ZuluString(),
            'duration_seconds'       => $durationSeconds,
            'status'                 => $this->status,
            'triggered_by_name'      => $this->triggeredBy?->name ?? 'Super Admin',
            'new_edible'             => $this->new_edible,
            'new_poisonous'          => $this->new_poisonous,
            'total_edible'           => $this->total_edible,
            'total_poisonous'        => $this->total_poisonous,
            'total_not_mushroom'     => $this->total_not_mushroom,
            'total_images'           => $this->total_images,
            'train_accuracy'         => $this->train_accuracy,
            'val_accuracy'           => $this->val_accuracy,
            'error'                  => $this->error,
            'scan_ids_count'         => is_array($this->scan_ids) ? count($this->scan_ids) : 0,
            'scans_marked_retrained' => $this->scans_marked_retrained,
        ];
    }
}

