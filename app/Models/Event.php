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
 * @property ?string $access_address ticket holders only, like access_info: the exact address (PLAN 10.14)
 * @property ?string $access_info ticket holders only: /t/{token} and its emails (ADR-012)
 * @property ?string $payment_info display-only price text; nothing is collected (ADR-012)
 * @property ?string $info_url public "More information" link, https only, /e/{slug} only (PLAN 10.15)
 * @property ?int $capacity
 * @property EventStatus $status
 */
#[Fillable(['title', 'description', 'starts_at', 'venue', 'access_address', 'access_info', 'payment_info', 'info_url', 'capacity', 'status'])]
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

    /**
     * Map links for the private address (PLAN 10.14). Plain search URLs: the
     * address is what the organizer typed, and both apps geocode it themselves —
     * nothing is looked up from here (zero external requests at runtime).
     */
    public function googleMapsUrl(): ?string
    {
        return $this->access_address
            ? 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($this->access_address)
            : null;
    }

    public function appleMapsUrl(): ?string
    {
        return $this->access_address
            ? 'https://maps.apple.com/?q='.rawurlencode($this->access_address)
            : null;
    }

    /**
     * The domain the "More information" button prints next to its label
     * (PLAN 10.15). Anyone can create events here, so the button says where it
     * leads instead of letting the label vouch for it: the host of the stored
     * URL, lower-cased, without a leading "www.". A host that is not plain
     * ASCII is shown as punycode, so a look-alike letter from another alphabet
     * cannot pass for a well-known domain.
     *
     * Null unless the stored value is an absolute https URL with a host — the
     * page then renders no button at all. EventRequest already guarantees that
     * on the way in; this is the same rule on the way out.
     */
    public function infoUrlHost(): ?string
    {
        if (! $this->info_url) {
            return null;
        }

        $parts = parse_url($this->info_url);

        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
            return null;
        }

        $host = $parts['host'];

        if (preg_match('/[^\x21-\x7e]/', $host) === 1 && function_exists('idn_to_ascii')) {
            $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $host;
        }

        $host = mb_strtolower($host);

        return str_starts_with($host, 'www.') && strlen($host) > 4 ? substr($host, 4) : $host;
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
