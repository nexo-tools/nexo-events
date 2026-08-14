<?php

namespace App\Models;

use Database\Factories\ApiTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A token an organizer issues to let one external system act on their account
 * through the integration API (ADR-010). Only the sha256 of the raw value is
 * stored — the same pattern as Ticket.token_hash (ADR-004/008): the raw token
 * is shown once at creation and is unrecoverable afterwards, so a database
 * dump grants nobody API access.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $token_hash
 * @property array<int, string> $abilities
 * @property ?Carbon $last_used_at
 * @property ?Carbon $revoked_at
 */
#[Fillable(['name'])] // token_hash and abilities are set internally, never mass-assigned
class ApiToken extends Model
{
    /** @use HasFactory<ApiTokenFactory> */
    use HasFactory;

    public const READ = 'events:read';

    public const WRITE = 'events:write';

    /** The scopes an organizer may pick from. Order is the order they are offered in. */
    public const ABILITIES = [self::READ, self::WRITE];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Opaque, 40 chars, like the QR tokens. Returned once and never stored. */
    public static function newRawToken(): string
    {
        return Str::random(40);
    }

    public static function hashFor(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function can(string $ability): bool
    {
        return in_array($ability, $this->abilities, true);
    }
}
