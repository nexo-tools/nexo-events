<?php

namespace App\Http\Controllers;

use App\Models\ApiToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The organizer's side of the integration API (SPEC AC-API-15/15b): issue a
 * token for one external system, see whether it is actually being used, cut it
 * off. Three verbs, no editing — a token's scopes are not a setting, they are
 * what the other side was told it could do.
 *
 * The whole screen rides NEXO_API_ENABLED. With the API off it does not exist,
 * rather than existing and being useless: an instance that never turns the API
 * on should look exactly like one built before the API did.
 */
class ApiTokenController extends Controller
{
    public function __construct()
    {
        abort_unless((bool) config('nexo.api.enabled', false), 404);
    }

    public function index(Request $request): View
    {
        return view('app.integrations.index', [
            'tokens' => $request->user()->apiTokens()->latest()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in(ApiToken::ABILITIES)],
        ]);

        $raw = ApiToken::newRawToken();

        $token = new ApiToken(['name' => $validated['name']]);
        $token->token_hash = ApiToken::hashFor($raw); // guarded — direct set, never mass-assigned
        $token->abilities = array_values($validated['abilities']);
        $request->user()->apiTokens()->save($token);

        // The one time this value exists outside the caller's hands. It goes
        // through a one-request flash and is never written anywhere else — if
        // the organizer closes the page without copying it, the answer is a new
        // token, not a recovery (ADR-004/008, same as a ticket QR).
        return redirect()->route('integrations.index')->with('api_token_plain', $raw);
    }

    public function revoke(Request $request, ApiToken $apiToken): RedirectResponse
    {
        abort_unless($apiToken->user_id === $request->user()->id, 403);

        if (! $apiToken->isRevoked()) {
            $apiToken->forceFill(['revoked_at' => now()])->save();
        }

        return redirect()->route('integrations.index')->with('status', __('Access revoked.'));
    }
}
