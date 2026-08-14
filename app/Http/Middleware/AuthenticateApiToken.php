<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer authentication for the integration API (ADR-010 §1/§3).
 *
 * Three things happen here, in this order, and the order is the design:
 *
 *  1. The env gate. With NEXO_API_ENABLED=false — the default, and what every
 *     standalone install runs — this answers 404 and nothing else executes. The
 *     gate is here rather than around the route registration because routes
 *     registered conditionally get their absence baked into `route:cache`
 *     (DEPLOYMENT.md has the SSO version of that scar). Routes always exist;
 *     whether they answer is a runtime decision.
 *  2. The token. sha256 of the bearer, looked up against the stored hash — the
 *     raw value was never stored, so this is the only way to find it.
 *  3. The scope. A read-only token cannot write, and vice versa.
 *
 * This middleware is registered ahead of ThrottleRequests in the framework's
 * priority list (bootstrap/app.php). Without that, Laravel's priority sorting
 * hoists the throttle to the front of the route's middleware, and then a
 * disabled API would answer 429 before it could answer 404, and the limiter
 * would key by IP instead of by token.
 */
class AuthenticateApiToken
{
    /** Where the resolved token is parked for the rest of the request. */
    public const ATTRIBUTE = 'nexo_api_token';

    public function handle(Request $request, Closure $next, ?string $ability = null): Response
    {
        if (! config('nexo.api.enabled', false)) {
            // 404, not 403: an instance with the API off is indistinguishable
            // from one that never had it.
            abort(404);
        }

        $raw = (string) $request->bearerToken();

        if ($raw === '') {
            return $this->unauthorized();
        }

        $token = ApiToken::query()->where('token_hash', ApiToken::hashFor($raw))->first();

        // Revoked reads the same as unknown on purpose: the answer to a token
        // that no longer works should not tell its holder whether it ever did.
        if ($token === null || $token->isRevoked()) {
            return $this->unauthorized();
        }

        if ($ability !== null && ! $token->can($ability)) {
            return new JsonResponse([
                'message' => 'This token does not have the required scope: '.$ability.'.',
            ], 403);
        }

        $request->attributes->set(self::ATTRIBUTE, $token);
        $request->setUserResolver(fn () => $token->user);

        // Visibility for the organizer ("last used 3 minutes ago" is how you
        // tell a live integration from a forgotten one). Timestamps off for this
        // write: a token that was used is not a token that was modified, and
        // updated_at is what the panel would otherwise have to lie about.
        $token->timestamps = false;
        $token->forceFill(['last_used_at' => now()])->saveQuietly();

        return $next($request);
    }

    private function unauthorized(): JsonResponse
    {
        return new JsonResponse([
            'message' => 'Invalid or revoked API token.',
        ], 401);
    }
}
