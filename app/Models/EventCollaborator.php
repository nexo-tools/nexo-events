<?php

namespace App\Models;

use Database\Factories\EventCollaboratorFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Someone the owner of an event invited to manage its tickets (ADR-011). The
 * invitation is bound to an email; it becomes a grant when an account with that
 * same, verified email accepts it.
 *
 * Nothing here is mass-assignable: every column is set by CollaboratorInviter,
 * the one place that knows the rules (hash, lifetimes, limits).
 *
 * @property int $id
 * @property int $event_id
 * @property ?int $user_id
 * @property string $email
 * @property ?string $invite_token_hash
 * @property ?int $invited_by
 * @property ?string $invited_via
 * @property Carbon $invited_at
 * @property Carbon $expires_at
 * @property ?Carbon $accepted_at
 * @property ?Carbon $revoked_at
 */
class EventCollaborator extends Model
{
    /** @use HasFactory<EventCollaboratorFactory> */
    use HasFactory;

    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const EXPIRED = 'expired';

    public const REVOKED = 'revoked';

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'invited_at' => 'datetime',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function status(): string
    {
        return match (true) {
            $this->revoked_at !== null => self::REVOKED,
            $this->accepted_at !== null => self::ACCEPTED,
            $this->expires_at->isPast() => self::EXPIRED,
            default => self::PENDING,
        };
    }

    /** Counts against the per-event limit: accepted, or an invitation still open. */
    public function isActive(): bool
    {
        return in_array($this->status(), [self::PENDING, self::ACCEPTED], true);
    }

    public static function hashFor(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    /**
     * @param  Builder<EventCollaborator>  $query
     * @return Builder<EventCollaborator>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where(fn (Builder $q) => $q->whereNotNull('accepted_at')->orWhere('expires_at', '>', now()));
    }
}
