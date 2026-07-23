<?php

namespace App\Models;

use App\Enums\TicketStatus;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $event_id
 * @property string $attendee_name
 * @property string $attendee_email
 * @property string $token_hash
 * @property TicketStatus $status
 */
#[Fillable(['attendee_name', 'attendee_email', 'status'])] // token_hash is set internally, never mass-assigned
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
        ];
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
