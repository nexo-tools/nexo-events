<?php

namespace App\Models;

use App\Enums\EventStatus;
use App\Enums\TicketStatus;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $organizer_id
 * @property string $title
 * @property string $slug
 * @property ?string $description
 * @property Carbon $starts_at
 * @property ?string $venue
 * @property ?int $capacity
 * @property EventStatus $status
 */
#[Fillable(['title', 'description', 'starts_at', 'venue', 'capacity', 'status'])]
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'capacity' => 'integer',
            'status' => EventStatus::class,
            'killed_at' => 'datetime',
        ];
    }

    /** @return HasMany<EventReport, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(EventReport::class);
    }

    /**
     * Unique visitor-days on the public page (cookieless, ADR-007 §6).
     *
     * @return HasMany<EventView, $this>
     */
    public function views(): HasMany
    {
        return $this->hasMany(EventView::class);
    }

    /** @return BelongsTo<User, $this> */
    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /** Tickets that count against capacity (everything not revoked). */
    public function issuedTicketsCount(): int
    {
        return $this->tickets()->where('status', '!=', TicketStatus::Revoked->value)->count();
    }

    public function isSoldOut(): bool
    {
        return $this->capacity !== null && $this->issuedTicketsCount() >= $this->capacity;
    }

    public static function uniqueSlugFor(string $title): string
    {
        $base = Str::slug($title) ?: 'evento';
        $slug = $base;
        $n = 1;

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$n);
        }

        return $slug;
    }
}
