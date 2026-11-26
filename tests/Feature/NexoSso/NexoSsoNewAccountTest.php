<?php

// «First time in <App>?» (§0.1 item 1, v2.1, transition mode only): a Nexo ID
// that matches no account — neither by sub nor by email — doesn't get a new
// account on the spot. The verified claims wait in the session (15 min, never
// in a URL) on /auth/nexo/new-account: «Create my account», or «I already have
// an account with another email», after which signing in with that account's
// password offers to join it to the waiting Nexo ID — «Link this account?»,
// where the person who typed the password confirms (NexoSsoLinkOnPasswordLogin). In
// sso mode, and in local mode, sign-up stays direct. (AC-NEWACCOUNT)

use App\Models\User;
use App\Services\NexoSso\NexoSsoFlows;
use App\Services\NexoSso\NexoSsoNewAccount;
use App\Services\NexoSso\NexoSsoPendingLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Hash;
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

    // A throwaway password login and a "remember me" login, so the suite
    // doesn't depend on the tool's own login code: the listener rides the
    // framework's Login event, whatever fires it.
    Route::middleware('web')->group(function (): void {
        Route::post('/nexo-sso-tests/password-login', fn (Request $request) => Auth::attempt($request->only('email', 'password'))
            ? response('signed-in')
            : response('wrong-password', 422));
        Route::post('/nexo-sso-tests/remember-login/{user}', function (User $user) {
            Auth::guard('web')->login($user, remember: true);

            return response('remembered');
        });
        Route::get('/nexo-sso-tests/login-card', fn () => Blade::render('<x-nexo-sso.link-notice />'));
    });
});

/** Nexo ID answers with an account no local one matches (another sub, another email). */
function nexoSsoNewPersonProvider(): void
{
    nexoSsoFakeProvider(['access_token' => 'fake', 'token_type' => 'Bearer', 'id_token' => nexoSsoIdToken([
        'sub' => 'new-sub',
        'sid' => 'new-sid',
        'email' => 'nexo-person@example.com',
        'email_verified' => true,
        'name' => 'Nexo Person',
    ])]);
}

/** Interactive sign-in with that Nexo ID, coming from $return. */
function nexoSsoFirstTime(TestCase $test, ?string $return = '/nexo-sso-tests/deep'): TestResponse
{
    nexoSsoNewPersonProvider();

    return nexoSsoCallback($test, mode: NexoSsoFlows::INTERACTIVE, return: $return);
}

/** The password sign-in offered no join: nothing waits on «Link this account?». */
function nexoSsoNoJoinOffered(): void
{
    expect(session(NexoSsoPendingLink::SESSION_KEY))->toBeNull()
        ->and(session('url.intended'))->not->toBe(route('nexo-sso.link.confirm'));
}

function nexoSsoLocalAccount(string $email = 'local@example.com'): User
{
    return User::factory()->create(['email' => $email, 'password' => Hash::make('secret-password')]);
}

test('AC-NEWACCOUNT-1: in transition a Nexo ID matching no account asks first — no account is created on the spot', function (): void {
    nexoSsoFirstTime($this)->assertRedirect(route('nexo-sso.new-account'));

    $this->assertGuest();
    expect(User::query()->count())->toBe(0)
        ->and(session(NexoSsoNewAccount::SESSION_KEY.'.claims.sub'))->toBe('new-sub');
});

test('AC-NEWACCOUNT-1: the claims wait in the session, never in the URL', function (): void {
    $location = (string) nexoSsoFirstTime($this)->headers->get('Location');

    expect($location)->toBe(route('nexo-sso.new-account'))
        ->not->toContain('new-sub')
        ->not->toContain('nexo-person');
});

test('AC-NEWACCOUNT-1: the screen asks «First time in <App>?» with both choices, never cached nor indexed', function (): void {
    nexoSsoFirstTime($this);

    $response = $this->get(route('nexo-sso.new-account'));

    $response->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex')
        ->assertSee('data-nexo-sso-new-account', false)
        ->assertSee(__('First time in :app?', ['app' => config('app.name')]))
        ->assertSee('nexo-person@example.com')
        ->assertSee('name="choice" value="create"', false)
        ->assertSee('name="choice" value="existing"', false)
        ->assertSee(__('Create my account'))
        ->assertSee(__('I already have an account with another email'));
    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
});

test('AC-NEWACCOUNT-2: «Create my account» creates it from the claims, signs in with a Nexo ID session and goes back', function (): void {
    nexoSsoFirstTime($this);

    $this->post(route('nexo-sso.new-account.store'), ['choice' => 'create'])
        ->assertRedirect(url('/nexo-sso-tests/deep'));

    $user = User::query()->where('email', 'nexo-person@example.com')->sole();
    expect($user->nexo_id_sub)->toBe('new-sub')
        ->and(session('nexo_sso.sub'))->toBe('new-sub')
        ->and(session('nexo_sso.sid'))->toBe('new-sid')
        ->and(session('nexo_sso.id_token'))->not->toBeEmpty()
        ->and(session(NexoSsoNewAccount::SESSION_KEY))->toBeNull();
    $this->assertAuthenticatedAs($user);
});

test('AC-NEWACCOUNT-3: «I already have an account with another email» → password → «Link this account?», nothing joined yet', function (): void {
    $local = nexoSsoLocalAccount();
    nexoSsoFirstTime($this);

    $this->post(route('nexo-sso.new-account.store'), ['choice' => 'existing'])->assertRedirect(route('login'));
    expect(session('url.intended'))->toBe(url('/nexo-sso-tests/deep'));

    $this->post('/nexo-sso-tests/password-login', ['email' => 'local@example.com', 'password' => 'secret-password'])->assertOk();

    // Whoever typed the password decides — on a shared computer the «First
    // time» choice may have been somebody else's. (AC-JOIN-CONFIRM)
    expect($local->fresh()->nexo_id_sub)->toBeNull()
        ->and(session('nexo_sso.sub'))->toBeNull()
        ->and(session('url.intended'))->toBe(route('nexo-sso.link.confirm'))
        ->and(session(NexoSsoPendingLink::SESSION_KEY.'.user'))->toBe((string) $local->getKey())
        ->and(session(NexoSsoPendingLink::SESSION_KEY.'.claims.sub'))->toBe('new-sub')
        ->and(session(NexoSsoNewAccount::SESSION_KEY))->toBeNull();
    $this->assertAuthenticatedAs($local);
    $this->get(route('nexo-sso.link.confirm'))->assertOk()
        ->assertSee('local@example.com')
        ->assertSee('nexo-person@example.com');
});

test('AC-NEWACCOUNT-3: confirmed there, that account is joined to the Nexo ID and goes where it was going', function (): void {
    $local = nexoSsoLocalAccount();
    nexoSsoFirstTime($this);
    $this->post(route('nexo-sso.new-account.store'), ['choice' => 'existing']);
    $this->post('/nexo-sso-tests/password-login', ['email' => 'local@example.com', 'password' => 'secret-password']);

    $this->post(route('nexo-sso.link.confirm.store'), ['choice' => 'link'])->assertRedirect(url('/nexo-sso-tests/deep'));

    expect($local->fresh()->nexo_id_sub)->toBe('new-sub')
        ->and($local->fresh()->email)->toBe('local@example.com')
        ->and(User::query()->count())->toBe(1)
        ->and(session('nexo_sso.sub'))->toBe('new-sub')
        ->and(session('nexo_sso.sid'))->toBe('new-sid')
        ->and(session('nexo_sso.notice.kind'))->toBe('linked')
        ->and(session(NexoSsoPendingLink::SESSION_KEY))->toBeNull();
    $this->assertAuthenticatedAs($local->fresh());
});

test('AC-NEWACCOUNT-3: «Not now» there leaves the account as it was', function (): void {
    $local = nexoSsoLocalAccount();
    nexoSsoFirstTime($this);
    $this->post(route('nexo-sso.new-account.store'), ['choice' => 'existing']);
    $this->post('/nexo-sso-tests/password-login', ['email' => 'local@example.com', 'password' => 'secret-password']);

    $this->post(route('nexo-sso.link.confirm.store'), ['choice' => 'cancel'])->assertRedirect(url('/nexo-sso-tests/deep'));

    expect($local->fresh()->nexo_id_sub)->toBeNull()
        ->and(session('nexo_sso.sub'))->toBeNull();
    $this->assertAuthenticatedAs($local);
});

test('AC-NEWACCOUNT-3: while it waits, the login card says what signing in will do', function (): void {
    nexoSsoFirstTime($this);
    $this->get('/nexo-sso-tests/login-card')->assertDontSee('data-nexo-sso-link-notice', false); // not chosen yet

    $this->post(route('nexo-sso.new-account.store'), ['choice' => 'existing']);

    $this->get('/nexo-sso-tests/login-card')
        ->assertSee('data-nexo-sso-link-notice', false)
        ->assertSee('nexo-person@example.com');
});

test('AC-NEWACCOUNT-4: after 15 minutes the window is closed: the password sign-in joins nothing', function (): void {
    $local = nexoSsoLocalAccount();
    nexoSsoFirstTime($this);
    $this->post(route('nexo-sso.new-account.store'), ['choice' => 'existing']);

    $this->travel(16)->minutes();

    $this->post('/nexo-sso-tests/password-login', ['email' => 'local@example.com', 'password' => 'secret-password'])->assertOk();

    expect($local->fresh()->nexo_id_sub)->toBeNull()
        ->and(session('nexo_sso.sub'))->toBeNull();
    nexoSsoNoJoinOffered();
    $this->assertAuthenticatedAs($local);
});

test('AC-NEWACCOUNT-4: the window counts from the Nexo ID sign-in, the question included', function (): void {
    $local = nexoSsoLocalAccount();
    nexoSsoFirstTime($this);
    $this->post(route('nexo-sso.new-account.store'), ['choice' => 'existing']);

    $this->travel(14)->minutes();
    $this->post('/nexo-sso-tests/password-login', ['email' => 'local@example.com', 'password' => 'secret-password']);
    expect(session('url.intended'))->toBe(route('nexo-sso.link.confirm'));

    $this->travel(2)->minutes();
    $this->post(route('nexo-sso.link.confirm.store'), ['choice' => 'link']);

    expect($local->fresh()->nexo_id_sub)->toBeNull()
        ->and(session('nexo_sso.sub'))->toBeNull();
});

test('AC-NEWACCOUNT-4: after 15 minutes the screen is gone too (back to the login, nothing to create)', function (): void {
    nexoSsoFirstTime($this);
    $this->travel(16)->minutes();

    $this->get(route('nexo-sso.new-account'))->assertRedirect(route('login'));
    $this->post(route('nexo-sso.new-account.store'), ['choice' => 'create'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('nexo_sso');

    expect(User::query()->count())->toBe(0);
});

test('AC-NEWACCOUNT-5: a wrong password offers nothing — and the right one, still inside the window, does', function (): void {
    $local = nexoSsoLocalAccount();
    nexoSsoFirstTime($this);
    $this->post(route('nexo-sso.new-account.store'), ['choice' => 'existing']);

    $this->post('/nexo-sso-tests/password-login', ['email' => 'local@example.com', 'password' => 'wrong'])->assertStatus(422);
    expect($local->fresh()->nexo_id_sub)->toBeNull();
    nexoSsoNoJoinOffered();
    $this->assertGuest();

    $this->post('/nexo-sso-tests/password-login', ['email' => 'local@example.com', 'password' => 'secret-password'])->assertOk();
    expect(session('url.intended'))->toBe(route('nexo-sso.link.confirm'));
    $this->post(route('nexo-sso.link.confirm.store'), ['choice' => 'link']);
    expect($local->fresh()->nexo_id_sub)->toBe('new-sub');
});

test('AC-NEWACCOUNT-6: if that Nexo ID got joined to another account meanwhile, the password sign-in stands but joins nothing', function (): void {
    $local = nexoSsoLocalAccount();
    nexoSsoFirstTime($this);
    $this->post(route('nexo-sso.new-account.store'), ['choice' => 'existing']);
    User::factory()->create(['email' => 'quick@example.com'])->forceFill(['nexo_id_sub' => 'new-sub'])->save();

    $this->post('/nexo-sso-tests/password-login', ['email' => 'local@example.com', 'password' => 'secret-password'])->assertOk();

    expect($local->fresh()->nexo_id_sub)->toBeNull()
        ->and(session('nexo_sso.sub'))->toBeNull()
        ->and(session('nexo_sso.notice.kind'))->toBe('error');
    nexoSsoNoJoinOffered();
    $this->assertAuthenticatedAs($local);
});

test('AC-NEWACCOUNT-6: an account already joined to another Nexo ID keeps it', function (): void {
    $local = nexoSsoLocalAccount();
    $local->forceFill(['nexo_id_sub' => 'old-sub'])->save();
    nexoSsoFirstTime($this);
    $this->post(route('nexo-sso.new-account.store'), ['choice' => 'existing']);

    $this->post('/nexo-sso-tests/password-login', ['email' => 'local@example.com', 'password' => 'secret-password']);

    expect($local->fresh()->nexo_id_sub)->toBe('old-sub')
        ->and(session('nexo_sso.sub'))->toBeNull()
        ->and(session('nexo_sso.notice.message'))->toBe(__('This account is already linked to another Nexo ID account.'));
    nexoSsoNoJoinOffered();
});

test('AC-NEWACCOUNT-7: only after choosing «I already have an account»: a password sign-in from the bare screen joins nothing', function (): void {
    $local = nexoSsoLocalAccount();
    nexoSsoFirstTime($this);

    $this->post('/nexo-sso-tests/password-login', ['email' => 'local@example.com', 'password' => 'secret-password'])->assertOk();

    expect($local->fresh()->nexo_id_sub)->toBeNull();
    nexoSsoNoJoinOffered();
});

test('AC-NEWACCOUNT-7: a "remember me" sign-in is not someone typing a password: it joins nothing', function (): void {
    $local = nexoSsoLocalAccount();
    nexoSsoFirstTime($this);
    $this->post(route('nexo-sso.new-account.store'), ['choice' => 'existing']);

    $this->post('/nexo-sso-tests/remember-login/'.$local->getKey())->assertOk();

    expect($local->fresh()->nexo_id_sub)->toBeNull();
    nexoSsoNoJoinOffered();
});

test('AC-NEWACCOUNT-8: a new Nexo ID sign-in forgets the one left waiting (no stale join later)', function (): void {
    $local = nexoSsoLocalAccount();
    nexoSsoFirstTime($this);
    $this->post(route('nexo-sso.new-account.store'), ['choice' => 'existing']);

    // Another «Continuar con Nexo ID» comes back (here, declined at Nexo ID).
    nexoSsoErrorCallback($this, 'access_denied');
    expect(session(NexoSsoNewAccount::SESSION_KEY))->toBeNull();

    $this->post('/nexo-sso-tests/password-login', ['email' => 'local@example.com', 'password' => 'secret-password'])->assertOk();
    expect($local->fresh()->nexo_id_sub)->toBeNull();
    nexoSsoNoJoinOffered();
});

test('AC-NEWACCOUNT-9: in sso mode and in local mode sign-up stays direct, as before', function (string $mode): void {
    config(['nexo-sso.auth_mode' => $mode]);
    $this->get(route('nexo-sso.new-account'))->assertNotFound();
    $this->post(route('nexo-sso.new-account.store'), ['choice' => 'create'])->assertNotFound();

    nexoSsoFirstTime($this)->assertRedirect(url('/nexo-sso-tests/deep'));

    expect(User::query()->where('nexo_id_sub', 'new-sub')->exists())->toBeTrue()
        ->and(session(NexoSsoNewAccount::SESSION_KEY))->toBeNull();
})->with(['sso', 'local']);

test('AC-NEWACCOUNT-9: a silent attempt in transition never asks: the visitor just stays a guest', function (): void {
    nexoSsoNewPersonProvider();

    nexoSsoCallback($this, mode: NexoSsoFlows::SILENT, return: '/nexo-sso-tests/landing')
        ->assertRedirect(url('/nexo-sso-tests/landing'));

    $this->assertGuest();
    expect(User::query()->count())->toBe(0)
        ->and(session(NexoSsoNewAccount::SESSION_KEY))->toBeNull();
});

test('AC-NEWACCOUNT-9: a verified email that matches an account still joins it directly (no question)', function (): void {
    $local = nexoSsoLocalAccount('nexo-person@example.com');

    nexoSsoFirstTime($this)->assertRedirect(url('/nexo-sso-tests/deep'));

    expect($local->fresh()->nexo_id_sub)->toBe('new-sub');
    $this->assertAuthenticatedAs($local->fresh());
});

test('AC-NEWACCOUNT-10: the choice needs the CSRF token', function (): void {
    nexoSsoFirstTime($this);
    nexoSsoEnforceCsrf($this);

    $this->post(route('nexo-sso.new-account.store'), ['choice' => 'create'])->assertStatus(419);

    expect(User::query()->count())->toBe(0);
});

test('AC-NEWACCOUNT-10: with nothing waiting the screen sends to the login', function (): void {
    $this->get(route('nexo-sso.new-account'))->assertRedirect(route('login'));
});
