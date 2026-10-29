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
 * @property ?string $external_ref
 * @property ?string $description
 * @property Carbon $starts_at
 * @property ?string $venue
 * @property ?string $access_info ticket holders only: /t/{token} and its emails (ADR-012)
 * @property ?string $payment_info display-only price text; nothing is collected (ADR-012)
 * @property ?int $capacity
 * @property EventStatus $status
 */
#[Fillable(['title', 'description', 'starts_at', 'venue', 'access_info', 'payment_info', 'capacity', 'status'])]
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

    /**
     * People invited to manage this event's tickets (ADR-011).
     *
     * @return HasMany<EventCollaborator, $this>
     */
    public function collaborators(): HasMany
    {
        return $this->hasMany(EventCollaborator::class);
    }

    /**
     * How long after the event a collaborator keeps access: long enough to
     * check the list the next day, short enough that a band does not hold its
     * audience's names indefinitely (ADR-011 §4).
     */
    public const COLLABORATOR_ACCESS_DAYS = 7;

    /**
     * Owner, or an accepted, unrevoked collaborator inside the access window.
     * The one question every "managed" route asks; owner-only routes keep
     * comparing organizer_id directly.
     */
    public function canBeManagedBy(User $user): bool
    {
        if ($this->organizer_id === $user->id) {
            return true;
        }

        if (now()->greaterThanOrEqualTo($this->starts_at->copy()->addDays(self::COLLABORATOR_ACCESS_DAYS))) {
            return false;
        }

        return $this->collaborators()
            ->where('user_id', $user->id)
            ->whereNotNull('accepted_at')
            ->whereNull('revoked_at')
            ->exists();
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

    /** People who actually came in — the door's number, next to issuedTicketsCount(). */
    public function checkedInCount(): int
    {
        return $this->tickets()->where('status', TicketStatus::CheckedIn->value)->count();
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
