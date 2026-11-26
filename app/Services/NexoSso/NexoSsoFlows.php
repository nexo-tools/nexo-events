<?php

declare(strict_types=1);

namespace App\Services\NexoSso;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Pending authorize requests, one per `state`. Each flow keeps its own PKCE
 * verifier, nonce, mode (interactive | silent | recheck | link) and return
 * path, so two tabs bouncing at the same time never overwrite each other's
 * state.
 *
 * Storage follows who started the flow:
 *   - interactive / silent (guest): a small map in the session, keyed by the
 *     state's hash — the state is bound to this browser (login CSRF guard).
 *   - link (signed in, "Link my account"): the same session map, plus the id
 *     of the user who asked — the callback refuses it for anybody else.
 *   - recheck (signed in): the cache, bound to the signed-in user. Concurrent
 *     tab restores write the session in parallel and the last writer wins;
 *     the cache entry survives that, and only this user can redeem it.
 *
 * Flows are single use and expire after 10 minutes.
 */
final class NexoSsoFlows
{
    public const INTERACTIVE = 'interactive';

    public const SILENT = 'silent';

    public const RECHECK = 'recheck';

    /** "Link my account": a signed-in local account asks to be joined to a Nexo ID. */
    public const LINK = 'link';

    private const SESSION_KEY = 'nexo_sso.flows';

    private const MAX_SESSION_FLOWS = 5;

    private const TTL = 600;

    /**
     * Mints state + PKCE + nonce for a new authorize request and stores the flow.
     *
     * @return array{state: string, challenge: string, nonce: string}
     */
    public function start(Request $request, string $mode, ?string $return = null): array
    {
        $state = Str::random(40);
        $verifier = Str::random(64); // 43–128 chars per RFC 7636
        $nonce = Str::random(40); // echoed back as the id_token nonce claim (AC-NONCE-1)

        $flow = [
            'mode' => $mode,
            'verifier' => $verifier,
            'nonce' => $nonce,
            'return' => $return,
            'created_at' => now()->getTimestamp(),
        ];

        if ($mode === self::RECHECK || $mode === self::LINK) {
            $flow['user'] = (string) $request->user()?->getAuthIdentifier();
        }

        if ($mode === self::RECHECK) {
            Cache::put($this->cacheKey($state), $flow, self::TTL);
        } else {
            $flows = $this->sessionFlows($request);
            $flows[$this->hash($state)] = $flow;
            $request->session()->put(self::SESSION_KEY, array_slice($flows, -self::MAX_SESSION_FLOWS, null, true));
        }

        return [
            'state' => $state,
            'challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'nonce' => $nonce,
        ];
    }

    /**
     * The flow this callback answers, removed so it can't be redeemed twice.
     * Null for an unknown, expired, replayed or foreign state. (AC-FLOW-2)
     * `user` is who started a link or recheck flow (null for guest flows): the
     * caller of a link flow checks it against whoever is signed in now.
     *
     * @return array{mode: string, verifier: string, nonce: string, return: ?string, user: ?string}|null
     */
    public function pull(Request $request, string $state): ?array
    {
        if ($state === '' || strlen($state) > 255) {
            return null;
        }

        $flows = $this->sessionFlows($request);
        $key = $this->hash($state);

        if (array_key_exists($key, $flows)) {
            $flow = $flows[$key];
            unset($flows[$key]);
            $request->session()->put(self::SESSION_KEY, $flows);

            return $this->shape($flow);
        }

        $flow = Cache::pull($this->cacheKey($state));
        if (! is_array($flow) || ($flow['mode'] ?? null) !== self::RECHECK || $this->expired($flow)) {
            return null;
        }

        $user = $request->user();
        if ($user === null || ! hash_equals((string) ($flow['user'] ?? ''), (string) $user->getAuthIdentifier())) {
            return null;
        }

        return $this->shape($flow);
    }

    /** @return array<string, array<string, mixed>> */
    private function sessionFlows(Request $request): array
    {
        $flows = $request->session()->get(self::SESSION_KEY, []);

        return is_array($flows)
            ? array_filter($flows, fn ($flow): bool => is_array($flow) && ! $this->expired($flow))
            : [];
    }

    /** @param  array<string, mixed>  $flow */
    private function expired(array $flow): bool
    {
        return now()->getTimestamp() - (int) ($flow['created_at'] ?? 0) > self::TTL;
    }

    /**
     * @param  array<string, mixed>  $flow
     * @return array{mode: string, verifier: string, nonce: string, return: ?string, user: ?string}
     */
    private function shape(array $flow): array
    {
        $return = $flow['return'] ?? null;
        $user = $flow['user'] ?? null;

        return [
            'mode' => (string) ($flow['mode'] ?? self::INTERACTIVE),
            'verifier' => (string) ($flow['verifier'] ?? ''),
            'nonce' => (string) ($flow['nonce'] ?? ''),
            'return' => is_string($return) ? $return : null,
            'user' => is_string($user) && $user !== '' ? $user : null,
        ];
    }

    private function hash(string $state): string
    {
        return hash('sha256', $state);
    }

    private function cacheKey(string $state): string
    {
        return 'nexo-sso.flow.'.$this->hash($state);
    }
}
