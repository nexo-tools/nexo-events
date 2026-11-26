<?php

// «Link my account» (§0.1 item 1, v2.1): a signed-in local account joins a Nexo
// ID explicitly — POST /auth/nexo/link starts a `link` flow bound to the
// signed-in user, and the callback joins the account whatever the two emails
// are. It is what protects the real password accounts of a tool in transition,
// whose owners may use another email on Nexo ID. (AC-JOIN)

use App\Models\User;
use App\Services\NexoSso\NexoSsoFlows;
use App\Services\NexoSso\NexoSsoPendingLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/NexoSsoTestSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'nexo-sso.enabled' => true,
        'nexo-sso.issuer' => 'https://nexoid.test',
        'nexo-sso.client_id' => '11111111-2222-3333-4444-555555555555',
        'nexo-sso.auth_mode' => 'transition',
        'nexo-sso.transition_until' => now()->addMonth()->format('Y-m-d'),
    ]);

    // A throwaway signed-in page that renders the banner, so the suite doesn't
    // depend on the tool's own panel.
    Route::middleware(['web', 'auth'])->get('/nexo-sso-tests/panel', fn () => Blade::render('<x-nexo-sso.transition-banner />'));
});

/** A local password account, signed in with its password (no Nexo ID keys in the session). */
function nexoSsoPasswordUser(string $email = 'local@example.com'): User
{
    return User::factory()->create(['email' => $email]);
}

/** The id_token of a Nexo ID account whose email is NOT the local one. */
function nexoSsoOtherEmailProvider(string $sub = 'user-uuid-0001'): void
{
    nexoSsoFakeProvider(['access_token' => 'fake', 'token_type' => 'Bearer', 'id_token' => nexoSsoIdToken([
        'sub' => $sub,
        'email' => 'someone-else@example.com',
        'email_verified' => true,
    ])]);
}

/** «Link my account» on «Link this account?» (or $choice), for whoever is signed in. */
function nexoSsoConfirmLink(TestCase $test, string $choice = 'link'): TestResponse
{
    return $test->post(route('nexo-sso.link.confirm.store'), ['choice' => $choice]);
}

/** A join of $user to the default Nexo ID (another email) waiting for confirmation, as the callback holds it. */
function nexoSsoPendingLinkSession(User $user, string $sub = 'user-uuid-0001', ?int $at = null): array
{
    return [NexoSsoPendingLink::SESSION_KEY => [
        'user' => (string) $user->getAuthIdentifier(),
        'claims' => nexoSsoClaims(['sub' => $sub, 'email' => 'someone-else@example.com']),
        'id_token' => 'pending-id-token',
        'return' => '/nexo-sso-tests/panel',
        'at' => $at ?? now()->getTimestamp(),
    ]];
}

/** Drives the callback of a pending link flow of $user. */
function nexoSsoLinkCallback(TestCase $test, User $user, array $query = ['code' => 'auth-code'], ?User $flowOwner = null): TestResponse
{
    return $test->actingAs($user)
        ->withSession(nexoSsoLinkFlowSession($flowOwner ?? $user))
        ->get(route('nexo-sso.callback', $query + ['state' => nexoSsoState()]));
}

test('AC-JOIN-1: «Link my account» starts a link flow bound to the signed-in user, without prompt', function (): void {
    nexoSsoFakeProvider();
    $user = nexoSsoPasswordUser();

    $response = $this->actingAs($user)->post(route('nexo-sso.link'), ['to' => '/nexo-sso-tests/panel']);

    expect((string) $response->headers->get('Location'))->toStartWith('https://nexoid.test/oauth/authorize?');
    $query = nexoSsoAuthorizeQuery($response);
    expect($query)->not->toHaveKey('prompt');

    $flow = nexoSsoStoredFlow($query['state']);
    expect($flow['mode'])->toBe(NexoSsoFlows::LINK)
        ->and($flow['user'])->toBe((string) $user->getKey())
        ->and($flow['return'])->toBe('/nexo-sso-tests/panel');
});

test('AC-JOIN-2: a Nexo ID with ANOTHER email is never joined on the spot: the person confirms first, seeing both emails', function (): void {
    nexoSsoOtherEmailProvider();
    $user = nexoSsoPasswordUser();

    nexoSsoLinkCallback($this, $user)->assertRedirect(route('nexo-sso.link.confirm'));

    expect($user->fresh()->nexo_id_sub)->toBeNull()
        ->and(session('nexo_sso.sub'))->toBeNull()
        ->and(session(NexoSsoPendingLink::SESSION_KEY.'.claims.sub'))->toBe('user-uuid-0001')
        ->and(session(NexoSsoPendingLink::SESSION_KEY.'.user'))->toBe((string) $user->getKey());
    $this->assertAuthenticatedAs($user);

    $response = $this->get(route('nexo-sso.link.confirm'));
    $response->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex')
        ->assertSee('data-nexo-sso-link-confirm', false)
        ->assertSee(__('Link this account to Nexo ID?'))
        ->assertSee('local@example.com')
        ->assertSee('someone-else@example.com')
        ->assertSee('name="choice" value="link"', false)
        ->assertSee('name="choice" value="cancel"', false)
        ->assertSee('action="'.route('nexo-sso.link.confirm.store').'"', false);
    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
});

test('AC-JOIN-2: confirmed, the account is joined to the Nexo ID with ANOTHER email and the session becomes a Nexo ID one', function (): void {
    nexoSsoOtherEmailProvider();
    $user = nexoSsoPasswordUser();
    nexoSsoLinkCallback($this, $user);
    $before = session()->getId();

    nexoSsoConfirmLink($this)->assertRedirect(url('/nexo-sso-tests/panel'));

    expect($user->fresh()->nexo_id_sub)->toBe('user-uuid-0001')
        ->and($user->fresh()->email)->toBe('local@example.com') // the local email is kept
        ->and(User::query()->count())->toBe(1)
        ->and(session('nexo_sso.sub'))->toBe('user-uuid-0001')
        ->and(session('nexo_sso.sid'))->toBe('nexo-sid-0001')
        ->and(session('nexo_sso.id_token'))->not->toBeEmpty()
        ->and(session()->getId())->not->toBe($before)
        ->and(session(NexoSsoPendingLink::SESSION_KEY))->toBeNull();
    $this->assertAuthenticatedAs($user->fresh());
});

test('AC-JOIN-2: a Nexo ID that vouches for this very account\'s email joins it directly — nothing to tell apart', function (): void {
    nexoSsoFakeProvider(['access_token' => 'fake', 'token_type' => 'Bearer', 'id_token' => nexoSsoIdToken([
        'email' => 'Local@Example.com',
        'email_verified' => true,
    ])]);
    $user = nexoSsoPasswordUser();

    nexoSsoLinkCallback($this, $user)->assertRedirect(url('/nexo-sso-tests/panel'));

    expect($user->fresh()->nexo_id_sub)->toBe('user-uuid-0001')
        ->and(session('nexo_sso.sub'))->toBe('user-uuid-0001')
        ->and(session(NexoSsoPendingLink::SESSION_KEY))->toBeNull();
});

test('AC-JOIN-2: the same email NOT verified by Nexo ID still asks first', function (): void {
    nexoSsoFakeProvider(['access_token' => 'fake', 'token_type' => 'Bearer', 'id_token' => nexoSsoIdToken([
        'email' => 'local@example.com',
        'email_verified' => false,
    ])]);
    $user = nexoSsoPasswordUser();

    nexoSsoLinkCallback($this, $user)->assertRedirect(route('nexo-sso.link.confirm'));

    expect($user->fresh()->nexo_id_sub)->toBeNull();
});

test('AC-JOIN-2: back on the panel the banner says it is joined, once, and no longer offers the link', function (): void {
    nexoSsoOtherEmailProvider();
    $user = nexoSsoPasswordUser();

    nexoSsoLinkCallback($this, $user);
    nexoSsoConfirmLink($this);

    $this->get('/nexo-sso-tests/panel')->assertSee('data-nexo-sso-linked', false)->assertDontSee('data-nexo-sso-link-form', false);
    $this->get('/nexo-sso-tests/panel')->assertDontSee('data-nexo-sso-transition-banner', false);
});

test('AC-JOIN-3: a Nexo ID already joined to ANOTHER account is refused and the local session goes on', function (): void {
    nexoSsoOtherEmailProvider('taken-sub');
    User::factory()->create(['email' => 'owner@example.com'])->forceFill(['nexo_id_sub' => 'taken-sub'])->save();
    $user = nexoSsoPasswordUser();

    nexoSsoLinkCallback($this, $user)->assertRedirect(url('/nexo-sso-tests/panel'));

    expect($user->fresh()->nexo_id_sub)->toBeNull()
        ->and(session('nexo_sso.sub'))->toBeNull();
    $this->assertAuthenticatedAs($user);
    $this->get('/nexo-sso-tests/panel')
        ->assertSee('data-nexo-sso-link-error', false)
        ->assertSee(__('That Nexo ID account is already linked to another :app account.', ['app' => config('app.name')]));
});

test('AC-JOIN-4: an account already joined to a DIFFERENT Nexo ID is refused (one account, one identity)', function (): void {
    config(['nexo-sso.auth_mode' => 'local']); // a linked account can still use its password in local mode
    nexoSsoOtherEmailProvider('new-sub');
    $user = nexoSsoPasswordUser();
    $user->forceFill(['nexo_id_sub' => 'old-sub'])->save();

    nexoSsoLinkCallback($this, $user)->assertRedirect(url('/nexo-sso-tests/panel'));

    expect($user->fresh()->nexo_id_sub)->toBe('old-sub')
        ->and(session('nexo_sso.sub'))->toBeNull();
    $this->assertAuthenticatedAs($user);
    expect(session('nexo_sso.notice.message'))->toBe(__('This account is already linked to another Nexo ID account.'));
});

test('AC-JOIN-5: a link flow coming back to ANOTHER signed-in user is refused — nobody gets joined', function (): void {
    Http::fake(); // must not even reach the token endpoint
    $starter = nexoSsoPasswordUser('starter@example.com');
    $other = nexoSsoPasswordUser('other@example.com');

    nexoSsoLinkCallback($this, $other, flowOwner: $starter)->assertRedirect(url('/nexo-sso-tests/panel'));

    Http::assertNothingSent();
    expect($starter->fresh()->nexo_id_sub)->toBeNull()
        ->and($other->fresh()->nexo_id_sub)->toBeNull()
        ->and(session('nexo_sso.sub'))->toBeNull();
    $this->assertAuthenticatedAs($other);
});

test('AC-JOIN-5: a link flow coming back with nobody signed in is refused', function (): void {
    nexoSsoOtherEmailProvider();
    $starter = nexoSsoPasswordUser();

    $this->withSession(nexoSsoLinkFlowSession($starter))
        ->get(route('nexo-sso.callback', ['code' => 'auth-code', 'state' => nexoSsoState()]))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('nexo_sso');

    $this->assertGuest();
    expect($starter->fresh()->nexo_id_sub)->toBeNull();
});

test('AC-JOIN-6: Nexo ID answering with an error (the person cancelled) leaves the account as it was', function (): void {
    nexoSsoFakeProvider();
    $user = nexoSsoPasswordUser();

    nexoSsoLinkCallback($this, $user, ['error' => 'access_denied'])->assertRedirect(url('/nexo-sso-tests/panel'));

    expect($user->fresh()->nexo_id_sub)->toBeNull();
    $this->assertAuthenticatedAs($user);
});

test('AC-JOIN-6: a bad id_token (wrong nonce) joins nothing', function (): void {
    nexoSsoFakeProvider(['access_token' => 'fake', 'token_type' => 'Bearer', 'id_token' => nexoSsoIdToken(['nonce' => 'another-nonce'])]);
    $user = nexoSsoPasswordUser();

    nexoSsoLinkCallback($this, $user)->assertRedirect(url('/nexo-sso-tests/panel'));

    expect($user->fresh()->nexo_id_sub)->toBeNull();
});

test('AC-JOIN-7: «Link my account» needs the CSRF token', function (): void {
    nexoSsoFakeProvider();
    nexoSsoEnforceCsrf($this);

    $this->actingAs(nexoSsoPasswordUser())->post(route('nexo-sso.link'))->assertStatus(419);

    expect(session('nexo_sso.flows'))->toBeNull();
    Http::assertNothingSent();
});

test('AC-JOIN-7: «Link my account» needs a signed-in user', function (): void {
    nexoSsoFakeProvider();

    $this->post(route('nexo-sso.link'))->assertRedirect();

    expect(session('nexo_sso.flows'))->toBeNull();
});

test('AC-JOIN-8: it is not offered in sso mode (404) and is a no-op for a session already governed by Nexo ID', function (): void {
    Http::fake();
    $user = User::factory()->create();

    $this->withSession(nexoSsoSignedIn($this, $user))
        ->post(route('nexo-sso.link'), ['to' => '/nexo-sso-tests/panel'])
        ->assertRedirect(url('/nexo-sso-tests/panel'));

    config(['nexo-sso.auth_mode' => 'sso']);
    $this->post(route('nexo-sso.link'))->assertNotFound();
    Http::assertNothingSent();
});

test('AC-JOIN-8: in local mode with SSO on it works too', function (): void {
    config(['nexo-sso.auth_mode' => 'local']);
    nexoSsoOtherEmailProvider();
    $user = nexoSsoPasswordUser();

    nexoSsoLinkCallback($this, $user)->assertRedirect(route('nexo-sso.link.confirm'));
    nexoSsoConfirmLink($this)->assertRedirect(url('/nexo-sso-tests/panel'));

    expect($user->fresh()->nexo_id_sub)->toBe('user-uuid-0001');
});

test('AC-JOIN-9: with Nexo ID unreachable the person stays where they were, told why', function (): void {
    Http::fake(fn () => throw new ConnectionException('provider down'));
    $user = nexoSsoPasswordUser();

    $this->actingAs($user)->post(route('nexo-sso.link'), ['to' => '/nexo-sso-tests/panel'])
        ->assertRedirect(url('/nexo-sso-tests/panel'));

    expect(session('nexo_sso.notice.kind'))->toBe('error');
});

test('AC-JOIN-9: an unsafe `to` never reaches the flow', function (): void {
    nexoSsoFakeProvider();

    $query = nexoSsoAuthorizeQuery($this->actingAs(nexoSsoPasswordUser())->post(route('nexo-sso.link'), ['to' => 'https://evil.test']));

    expect(nexoSsoStoredFlow($query['state'])['return'])->toBe(config('nexo-sso.home'));
});

test('AC-JOIN-10: the transition banner offers it as a POST form, and says another email works', function (): void {
    $this->actingAs(nexoSsoPasswordUser());

    $html = $this->get('/nexo-sso-tests/panel')->assertOk()->getContent();

    expect($html)->toContain('data-nexo-sso-transition-banner')
        ->toContain('method="POST" action="'.route('nexo-sso.link').'"')
        ->toContain('name="_token"')
        ->toContain('name="to" value="/nexo-sso-tests/panel"')
        ->toContain(__('Link my account'))
        ->toContain(__('You can link this account to a Nexo ID with a different email.'))
        ->not->toContain(route('nexo-sso.enter'));
});

test('AC-JOIN-10: no banner for a Nexo ID session', function (): void {
    $this->withSession(nexoSsoSignedIn($this, User::factory()->create()))->get('/nexo-sso-tests/panel')
        ->assertDontSee('data-nexo-sso-transition-banner', false);
});

test('AC-JOIN-10: no banner outside transition', function (string $mode): void {
    config(['nexo-sso.auth_mode' => $mode]);

    $this->actingAs(nexoSsoPasswordUser())->get('/nexo-sso-tests/panel')
        ->assertDontSee('data-nexo-sso-transition-banner', false);
})->with(['local', 'sso']);

test('AC-JOIN-CONFIRM-1: «Not now» joins nothing, the password session goes on, and the answer can\'t be replayed', function (): void {
    $user = nexoSsoPasswordUser();
    $this->actingAs($user)->withSession(nexoSsoPendingLinkSession($user));

    nexoSsoConfirmLink($this, 'cancel')->assertRedirect(url('/nexo-sso-tests/panel'));

    expect($user->fresh()->nexo_id_sub)->toBeNull()
        ->and(session('nexo_sso.sub'))->toBeNull()
        ->and(session(NexoSsoPendingLink::SESSION_KEY))->toBeNull()
        ->and(session('nexo_sso.notice'))->toBeNull();
    $this->assertAuthenticatedAs($user);

    nexoSsoConfirmLink($this)->assertRedirect(url(config('nexo-sso.home')));
    expect($user->fresh()->nexo_id_sub)->toBeNull()
        ->and(session('nexo_sso.notice.kind'))->toBe('error');
});

test('AC-JOIN-CONFIRM-1: anything but «link» is «Not now»', function (): void {
    $user = nexoSsoPasswordUser();
    $this->actingAs($user)->withSession(nexoSsoPendingLinkSession($user));

    $this->post(route('nexo-sso.link.confirm.store'))->assertRedirect(url('/nexo-sso-tests/panel'));

    expect($user->fresh()->nexo_id_sub)->toBeNull();
});

test('AC-JOIN-CONFIRM-2: a join held for one user is never offered to, nor confirmed by, another one signed in later', function (): void {
    $starter = nexoSsoPasswordUser('starter@example.com');
    $other = nexoSsoPasswordUser('other@example.com');
    $this->actingAs($other)->withSession(nexoSsoPendingLinkSession($starter));

    $this->get(route('nexo-sso.link.confirm'))->assertRedirect(url(config('nexo-sso.home')));
    nexoSsoConfirmLink($this)->assertRedirect(url(config('nexo-sso.home')));

    expect($starter->fresh()->nexo_id_sub)->toBeNull()
        ->and($other->fresh()->nexo_id_sub)->toBeNull()
        ->and(session('nexo_sso.sub'))->toBeNull()
        ->and(session(NexoSsoPendingLink::SESSION_KEY))->toBeNull();
});

test('AC-JOIN-CONFIRM-3: after 15 minutes there is nothing left to confirm', function (): void {
    $user = nexoSsoPasswordUser();
    $this->actingAs($user)->withSession(nexoSsoPendingLinkSession($user, at: now()->subMinutes(16)->getTimestamp()));

    $this->get(route('nexo-sso.link.confirm'))->assertRedirect(url(config('nexo-sso.home')));

    $this->withSession(nexoSsoPendingLinkSession($user, at: now()->subMinutes(16)->getTimestamp()));
    nexoSsoConfirmLink($this);
    expect($user->fresh()->nexo_id_sub)->toBeNull()
        ->and(session('nexo_sso.notice.message'))->toBe(__('Your Nexo ID sign-in expired. Please try again.'));
});

test('AC-JOIN-CONFIRM-4: the answer needs the CSRF token', function (): void {
    nexoSsoEnforceCsrf($this);
    $user = nexoSsoPasswordUser();

    $this->actingAs($user)->withSession(nexoSsoPendingLinkSession($user))
        ->post(route('nexo-sso.link.confirm.store'), ['choice' => 'link'])
        ->assertStatus(419);

    expect($user->fresh()->nexo_id_sub)->toBeNull();
});

test('AC-JOIN-CONFIRM-5: a Nexo ID joined to another account while the question waited is refused at the answer', function (): void {
    $user = nexoSsoPasswordUser();
    $this->actingAs($user)->withSession(nexoSsoPendingLinkSession($user, sub: 'raced-sub'));
    User::factory()->create(['email' => 'winner@example.com'])->forceFill(['nexo_id_sub' => 'raced-sub'])->save();

    nexoSsoConfirmLink($this)->assertRedirect(url('/nexo-sso-tests/panel'));

    expect($user->fresh()->nexo_id_sub)->toBeNull()
        ->and(session('nexo_sso.sub'))->toBeNull()
        ->and(session('nexo_sso.notice.message'))->toBe(__('That Nexo ID account is already linked to another :app account.', ['app' => config('app.name')]));
});

test('AC-JOIN-CONFIRM-6: it doesn\'t exist in sso mode, nor for a guest', function (): void {
    $user = nexoSsoPasswordUser();
    $this->withSession(nexoSsoPendingLinkSession($user));

    $this->get(route('nexo-sso.link.confirm'))->assertRedirect();
    nexoSsoConfirmLink($this)->assertRedirect();
    expect($user->fresh()->nexo_id_sub)->toBeNull();

    config(['nexo-sso.auth_mode' => 'sso']);
    $this->actingAs($user)->get(route('nexo-sso.link.confirm'))->assertNotFound();
    nexoSsoConfirmLink($this)->assertNotFound();
    expect($user->fresh()->nexo_id_sub)->toBeNull();
});

test('AC-JOIN-CONFIRM-7: a new Nexo ID sign-in forgets a join left waiting', function (): void {
    nexoSsoFakeProvider();
    $user = nexoSsoPasswordUser();
    $this->withSession(nexoSsoPendingLinkSession($user));

    nexoSsoCallback($this);

    expect(session(NexoSsoPendingLink::SESSION_KEY))->toBeNull();
});
