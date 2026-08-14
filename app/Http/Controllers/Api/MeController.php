<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateApiToken;
use App\Models\ApiToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The handshake an external system runs when somebody pastes a token into it:
 * which account is this, and can it publish? `email_verified` is here because
 * publishing without it fails (ADR-007 §1) — the consumer is meant to refuse
 * the link at setup time, when a person is watching, instead of discovering it
 * the evening they publish an event.
 */
class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var ApiToken $token */
        $token = $request->attributes->get(AuthenticateApiToken::ATTRIBUTE);
        $user = $token->user;

        return new JsonResponse([
            'email' => $user->email,
            'email_verified' => $user->hasVerifiedEmail(),
            'scopes' => $token->abilities,
        ]);
    }
}
