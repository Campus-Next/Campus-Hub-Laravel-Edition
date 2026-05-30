<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['user_id', 'event_id', 'status', 'unique_code', 'cancelled_at'])]
class EventParticipant extends Model
{
    protected function casts(): array
    {
        return [
            'cancelled_at' => 'datetime',
        ];
    }

    public static function generateUniqueCode(Event $event): string
    {
        do {
            $code = strtoupper(Str::random(4));
        } while ($event->participants()->where('unique_code', $code)->exists());

        return $code;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
