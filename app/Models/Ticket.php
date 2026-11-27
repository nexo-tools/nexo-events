<?php

namespace App\Models;

use App\Enums\TicketStatus;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $event_id
 * @property string $attendee_name
 * @property string $attendee_email
 * @property string|null $attendee_phone optional, owner-only (PLAN 10.13)
 * @property string|null $locale
 * @property string $token_hash
 * @property TicketStatus $status
 * @property bool $is_guest a guest takes no room (AC-PANEL-11); set by the organizer, never mass-assigned
 */
#[Fillable(['attendee_name', 'attendee_email', 'attendee_phone', 'locale', 'status'])] // token_hash and is_guest are set internally, never mass-assigned
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'is_guest' => 'boolean',
        ];
    }

    /**
     * The tickets that take a spot of the capacity: neither released (ADR-011 §6)
     * nor guests (AC-PANEL-11). Every capacity count goes through here — sold
     * out, registration, the capacity floor, giving a spot back, the API — so
     * they never disagree.
     *
     * @param  Builder<Ticket>  $query
     */
    public function scopeCountsAgainstCapacity(Builder $query): void
    {
        $query->where('status', '!=', TicketStatus::Revoked->value)->where('is_guest', false);
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return HasOne<Checkin, $this> */
    public function checkin(): HasOne
    {
        return $this->hasOne(Checkin::class);
    }
}
