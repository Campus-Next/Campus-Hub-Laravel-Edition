<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'organizer_id',
    'category_id',
    'title',
    'description',
    'start_date',
    'end_date',
    'location',
    'max_participants',
    'registration_fee',
    'registration_open',
    'registration_deadline',
])]
class Event extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'start_date' => 'datetime',
            'end_date' => 'datetime',
            'registration_open' => 'datetime',
            'registration_deadline' => 'datetime',
        ];
    }

    public function images(): HasMany
    {
        return $this->hasMany(Image::class, 'event_id', 'id');
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(EventParticipant::class);
    }

    public function absentSchedule(): HasOne
    {
        return $this->hasOne(EventAbsentSchedule::class);
    }

    public function eventLinks(): HasMany
    {
        return $this->hasMany(EventLink::class);
    }
}
