<?php

declare(strict_types=1);

namespace App\Services\NexoSso;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sessions Nexo ID ended (back-channel logout), kept for
 * `revocation_ttl_hours`. A revocation list instead of deleting session rows:
 * it works with any session driver and doesn't depend on Laravel's session id,
 * which regenerates. (§2.3)
 *
 *   by sid  → that one Nexo ID session (one device) is over.
 *   by sub  → every session of that person signed in up to revoked_at is over
 *             (account deletion: Nexo ID sends a token with no sid).
 */
final class NexoSsoRevocations
{
    public const TABLE = 'nexo_sso_revocations';

    public function revoke(?string $sid, ?string $sub): void
    {
        $now = now();

        DB::table(self::TABLE)->insert([
            'sid' => $sid !== null && $sid !== '' ? $sid : null,
            'sub' => $sub !== null && $sub !== '' ? $sub : null,
            'revoked_at' => $now,
            'expires_at' => $now->copy()->addHours(max(1, (int) config('nexo-sso.revocation_ttl_hours', 24))),
        ]);
    }

    /**
     * $authenticatedAt: when this tool session signed in (unix). Unknown (a
     * session from before v2) counts as "before everything".
     *
     * Fail-open: asked on every signed-in request, so a lookup that can't run
     * (the table not migrated yet during a deploy, a database hiccup) is
     * reported and answers "not revoked" instead of failing the whole app. The
     * periodic re-validation still closes the session within the window. (R8)
     */
    public function isRevoked(?string $sid, ?string $sub, ?int $authenticatedAt): bool
    {
        $sid = $sid !== '' ? $sid : null;
        $sub = $sub !== '' ? $sub : null;

        if ($sid === null && $sub === null) {
            return false;
        }

        $signedInAt = now()->setTimestamp($authenticatedAt ?? 0);

        try {
            return DB::table(self::TABLE)
                ->where('expires_at', '>', now())
                ->where(function (Builder $query) use ($sid, $sub, $signedInAt): void {
                    if ($sid !== null) {
                        $query->orWhere('sid', $sid);
                    }

                    if ($sub !== null) {
                        $query->orWhere(fn (Builder $bySub) => $bySub
                            ->whereNull('sid')
                            ->where('sub', $sub)
                            ->where('revoked_at', '>=', $signedInAt));
                    }
                })
                ->exists();
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /** Deletes the expired rows; returns how many. Run daily by `nexo-sso:prune`. */
    public function prune(): int
    {
        return DB::table(self::TABLE)->where('expires_at', '<=', now())->delete();
    }
}
