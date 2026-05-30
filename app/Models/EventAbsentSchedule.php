<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'event_id',
    'run_at',
    'processed_at',
    'cancelled_at',
])]
class EventAbsentSchedule extends Model
{
    protected function casts(): array
    {
        return [
            'run_at' => 'datetime',
            'processed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
