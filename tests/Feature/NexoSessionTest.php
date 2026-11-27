<?php

// Nexo ID controls this tool's session, on this tool's real routes (PLAN
// 10.25–10.27, docs/specs/SPEC-nexo-session.md). The nexo-sso-client template
// suite (tests/Feature/NexoSso/) proves the mechanism on throwaway routes; this
// file proves what Nexo Events needs from it: the production account that joins
// on its own, a collaborator invitation that survives Nexo ID, a door that is
// never bounced mid-event, an Integration API that never sees any of it, and
// the auth mode on /login, /register and the reset.

use App\Enums\TicketStatus;
use App\Mail\NexoIdLinked;
use App\Models\Event;
use App\Models\EventCollaborator;
use App\Models\User;
use App\Services\CollaboratorInviter;
use App\Services\EventRegistrar;
use App\Services\NexoSso\NexoSsoFlows;
use App\Services\NexoSso\NexoSsoRevocations;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/NexoSso/NexoSsoTestSupport.php';
require_once __DIR__.'/Api/ApiTestSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'nexo-sso.enabled' => true,
        'nexo-sso.issuer' => 'https://nexoid.test',
        'nexo-sso.client_id' => '11111111-2222-3333-4444-555555555555',
        'nexo-sso.recheck_minutes' => 15,
    ]);
});

/** Production's auth mode for this tool (CONTRATO-F3 D2): Nexo ID only. */
function nexoSessionSsoMode(): void
{
    config(['nexo-sso.auth_mode' => 'sso']);
}

/** Invites $email to $event as its owner; returns the raw token of the mailed link. */
function nexoSessionInvite(Event $event, string $email): string
{
    $outcome = app(CollaboratorInviter::class)->invite($event, $email, $event->organizer);

    return Str::afterLast((string) $outcome['url'], '/');
}

/** The callback of an interactive flow back from Nexo ID, signed in as these claims. */
function nexoSessionCallbackAs(object $test, array $claims, string $return): TestResponse
{
    // A fresh fake each time: stubs stack, and the first match would win.
    Http::swap(new Factory);
    nexoSsoFakeProvider(['access_token' => 'fake', 'token_type' => 'Bearer', 'id_token' => nexoSsoIdToken($claims)]);

    return nexoSsoCallback($test, 'auth-code', NexoSsoFlows::INTERACTIVE, $return);
}

// ── The auth mode on this tool's routes (AC-SESSION-1) ─────────────────────

it('AC-SESSION-1: sso mode closes every local credential surface and keeps only Nexo ID on /login', function (): void {
    nexoSessionSsoMode();
    $user = User::factory()->create(['password' => Hash::make('secret-password')]);

    $this->post('/login', ['email' => $user->email, 'password' => 'secret-password'])->assertNotFound();
    $this->assertGuest();

    $this->get('/register')->assertNotFound();
    $this->post('/register', ['name' => 'X', 'email' => 'x@example.com', 'password' => 'secret-password-1', 'password_confirmation' => 'secret-password-1'])->assertNotFound();
    expect(User::where('email', 'x@example.com')->exists())->toBeFalse();

    $this->get('/forgot-password')->assertNotFound();
    $this->post('/forgot-password', ['email' => $user->email])->assertNotFound();
    $this->get('/reset-password/whatever')->assertNotFound();

    $html = (string) $this->get('/login')->assertOk()->getContent();
    expect($html)->toContain(route('nexo-sso.redirect'))
        ->and($html)->toContain(__('nexo.auth.continue'))
        ->and(str_contains($html, 'name="password"'))->toBeFalse()
        ->and(str_contains($html, 'name="remember"'))->toBeFalse()
        // «Crear cuenta» is Nexo ID's sign-up through this tool, and back to
        // the panel (nexo-ui v2.2), never the issuer's /register by hand.
        ->and($html)->toContain('href="'.e(route('nexo-sso.enter', ['to' => '/app', 'signup' => 1])).'"')
        ->and(str_contains($html, 'https://nexoid.test/register'))->toBeFalse();
});

it('AC-SESSION-1: sso mode sends a guest deep link through Nexo ID and back to itself, never /login', function (): void {
    nexoSessionSsoMode();
    $event = Event::factory()->create();

    $this->get('/app')->assertRedirect(route('nexo-sso.enter', ['to' => '/app']));
    $this->get(route('events.manage', $event))
        ->assertRedirect(route('nexo-sso.enter', ['to' => "/app/eventos/{$event->id}/gestion"]));
});

it('AC-SESSION-1: local mode keeps the password form, its remember me and /login', function (): void {
    config(['nexo-sso.auth_mode' => 'local']);
    $user = User::factory()->create(['password' => Hash::make('secret-password')]);

    $this->get('/app')->assertRedirect(route('login'));
    $html = (string) $this->get('/login')->assertOk()->getContent();
    expect(strpos($html, route('nexo-sso.redirect')))->toBeLessThan(strpos($html, 'name="password"'))
        ->and($html)->toContain('name="remember"');

    $response = $this->post('/login', ['email' => $user->email, 'password' => 'secret-password', 'remember' => 'on']);
    $response->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);
    expect(collect($response->headers->getCookies())->contains(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_')))->toBeTrue();
});

it('AC-SESSION-1: transition never remembers a password sign-in, refuses a linked account and its reset link', function (): void {
    config(['nexo-sso.auth_mode' => 'transition', 'nexo-sso.transition_until' => now()->addMonth()->format('Y-m-d')]);
    Notification::fake();
    $unlinked = User::factory()->create(['password' => Hash::make('secret-password')]);
    $linked = User::factory()->create(['password' => Hash::make('secret-password')]);
    $linked->forceFill(['nexo_id_sub' => 'linked-sub'])->save();

    $this->post('/login', ['email' => $linked->email, 'password' => 'secret-password'])
        ->assertSessionHasErrors(['email' => __('This account already signs in with Nexo ID.')]);
    $this->assertGuest();

    $this->post('/forgot-password', ['email' => $linked->email])->assertSessionHas('status');
    Notification::assertNothingSentTo($linked);

    $response = $this->post('/login', ['email' => $unlinked->email, 'password' => 'secret-password', 'remember' => 'on']);
    $response->assertRedirect(route('dashboard'));
    expect(collect($response->headers->getCookies())->contains(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_')))->toBeFalse();

    // The panel offers «Unir mi cuenta».
    $this->get(route('dashboard'))->assertOk()->assertSee('data-nexo-sso-transition-banner', false);
});

it('AC-SESSION-1: transition opens the password fold for whoever chose «Ya tengo una cuenta con otro email», and only then', function (): void {
    config(['nexo-sso.auth_mode' => 'transition', 'nexo-sso.transition_until' => now()->addMonth()->format('Y-m-d')]);
    $fold = function (string $html): string {
        expect(preg_match('/<details\b[^>]*data-nexo-password-fold[^>]*>/', $html, $m))->toBe(1);

        return $m[0];
    };
    $pending = fn (bool $armed): array => ['nexo_sso.new_account' => [
        'claims' => ['sub' => 'new-sub', 'email' => 'otra@example.com', 'email_verified' => true],
        'id_token' => 'x', 'return' => '/app', 'at' => now()->getTimestamp(), 'armed' => $armed,
    ]];

    expect($fold((string) $this->get('/login')->assertOk()->getContent()))->not->toMatch('/\sopen\b/');
    expect($fold((string) $this->withSession($pending(false))->get('/login')->getContent()))->not->toMatch('/\sopen\b/');

    $html = (string) $this->withSession($pending(true))->get('/login')->assertOk()->getContent();
    expect($fold($html))->toMatch('/\sopen\b/')
        ->and($html)->toContain('otra@example.com');
});

it('AC-SESSION-1: standalone (SSO off) has no Nexo ID anywhere and local auth as always', function (): void {
    config(['nexo-sso.enabled' => false, 'nexo-sso.auth_mode' => 'sso']);
    $user = User::factory()->create(['password' => Hash::make('secret-password')]);

    $this->get('/app')->assertRedirect(route('login'));
    $html = (string) $this->get('/login')->assertOk()->getContent();
    expect($html)->toContain('name="password"')
        ->and(str_contains($html, '/auth/nexo/'))->toBeFalse();

    $this->post('/login', ['email' => $user->email, 'password' => 'secret-password'])->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);
});

// ── The production account that joins on its own (AC-SESSION-2) ───────────

it('AC-SESSION-2: the verified local account joins itself the first time it enters through Nexo ID with the same email', function (): void {
    nexoSessionSsoMode();
    Mail::fake();
    // The one verified local account production had on 2026-11-26: password
    // only, never linked, with its events.
    $owner = User::factory()->create(['email' => 'hola@venue.example', 'email_verified_at' => now()->subMonths(2)]);
    $event = Event::factory()->for($owner, 'organizer')->create(['title' => '1er Aniversario']);
    expect($owner->nexo_id_sub)->toBeNull();

    nexoSessionCallbackAs($this, ['sub' => 'nexo-sub-venue', 'email' => 'hola@venue.example', 'email_verified' => true], '/app')
        ->assertRedirect(url('/app'));

    // The same row — not a new account — now joined, signed in, with its events.
    $this->assertAuthenticatedAs($owner);
    expect(User::count())->toBe(1)
        ->and($owner->fresh()->nexo_id_sub)->toBe('nexo-sub-venue')
        ->and($event->fresh()->organizer_id)->toBe($owner->id);
    $this->get(route('dashboard'))->assertOk()->assertSee('1er Aniversario');

    Mail::assertQueued(NexoIdLinked::class, fn (NexoIdLinked $mail) => $mail->hasTo('hola@venue.example'));

    // Coming back later is by sub, even with another email: nothing new, no second mail.
    $this->post(route('nexo-sso.logout'));
    $this->assertGuest();
    nexoSessionCallbackAs($this, ['sub' => 'nexo-sub-venue', 'email' => 'otro@venue.example', 'email_verified' => true], '/app');
    $this->assertAuthenticatedAs($owner);
    expect(User::count())->toBe(1)->and($owner->fresh()->email)->toBe('hola@venue.example');
    Mail::assertQueuedCount(1);
});

it('AC-SESSION-2: an unconfirmed local account with that email is joined and its email counts as verified', function (): void {
    nexoSessionSsoMode();
    Mail::fake();
    $user = User::factory()->unverified()->create(['email' => 'organiza@example.com']);

    nexoSessionCallbackAs($this, ['sub' => 'nexo-sub-2', 'email' => 'organiza@example.com', 'email_verified' => true], '/app');

    $this->assertAuthenticatedAs($user);
    // Nexo ID vouched for this very address: the publish gate opens.
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('AC-SESSION-2: Nexo ID not vouching for the email joins nothing', function (): void {
    nexoSessionSsoMode();
    $user = User::factory()->create(['email' => 'organiza@example.com']);

    nexoSessionCallbackAs($this, ['sub' => 'nexo-sub-3', 'email' => 'organiza@example.com', 'email_verified' => false], '/app');

    $this->assertGuest();
    expect($user->fresh()->nexo_id_sub)->toBeNull()->and(User::count())->toBe(1);
});

// ── A collaborator invitation through Nexo ID (AC-SESSION-3) ───────────────

it('AC-SESSION-3: the invitation link takes a signed-out band through Nexo ID — a new account — and back to accept it', function (): void {
    nexoSessionSsoMode();
    Mail::fake();
    $event = Event::factory()->create();
    $token = nexoSessionInvite($event, 'banda@example.com');
    $invitation = "/app/invitaciones/{$token}";

    // 1. The link from the mail, signed out: straight to Nexo ID, carrying itself.
    $this->get(route('invitations.show', ['token' => $token]))
        ->assertRedirect(route('nexo-sso.enter', ['to' => $invitation]));

    // Nexo ID, where the band creates its account (verified email) and signs in.
    $band = ['sub' => 'nexo-sub-band', 'email' => 'banda@example.com', 'email_verified' => true, 'name' => 'La Banda'];
    nexoSsoFakeProvider(['access_token' => 'fake', 'token_type' => 'Bearer', 'id_token' => nexoSsoIdToken($band)]);

    // Since nexo-sso-client v2.3 through the page that jumps there (AC-ENTER-7).
    $toNexoId = $this->get(route('nexo-sso.enter', ['to' => $invitation]))->assertOk();
    expect(nexoSsoAuthorizeUrl($toNexoId))->toStartWith('https://nexoid.test/oauth/authorize?')
        ->and(nexoSsoStoredFlow(nexoSsoAuthorizeQuery($toNexoId)['state'])['return'])->toBe($invitation);

    // 2. Back from Nexo ID with a new account.
    nexoSsoCallback($this, 'auth-code', NexoSsoFlows::INTERACTIVE, $invitation)
        ->assertRedirect(url($invitation));

    $band = User::where('email', 'banda@example.com')->firstOrFail();
    expect($band->nexo_id_sub)->toBe('nexo-sub-band')->and($band->hasVerifiedEmail())->toBeTrue();

    // 3. Back on the invitation, signed in: it can be accepted.
    $this->get($invitation)->assertOk()->assertSee(route('invitations.accept', ['token' => $token]), false);
    $this->post(route('invitations.accept', ['token' => $token]))->assertRedirect(route('events.manage', $event));

    expect(EventCollaborator::where('event_id', $event->id)->where('user_id', $band->id)->whereNotNull('accepted_at')->exists())->toBeTrue()
        ->and($event->fresh()->canBeManagedBy($band))->toBeTrue();
});

it('AC-SESSION-3: a band that already had a local account with the invited email is joined and accepts', function (): void {
    nexoSessionSsoMode();
    Mail::fake();
    $event = Event::factory()->create();
    $band = User::factory()->unverified()->create(['email' => 'banda@example.com']);
    $token = nexoSessionInvite($event, 'banda@example.com');
    $invitation = "/app/invitaciones/{$token}";

    nexoSessionCallbackAs($this, ['sub' => 'nexo-sub-band', 'email' => 'banda@example.com', 'email_verified' => true], $invitation)
        ->assertRedirect(url($invitation));

    $this->assertAuthenticatedAs($band);
    $this->post(route('invitations.accept', ['token' => $token]))->assertRedirect(route('events.manage', $event));
    expect($event->fresh()->canBeManagedBy($band))->toBeTrue();
});

it('AC-SESSION-3: coming back with another Nexo ID email still says the invitation is for another address', function (): void {
    nexoSessionSsoMode();
    $event = Event::factory()->create();
    $token = nexoSessionInvite($event, 'banda@example.com');

    nexoSessionCallbackAs($this, ['sub' => 'nexo-sub-x', 'email' => 'otra@example.com', 'email_verified' => true], "/app/invitaciones/{$token}");

    $this->get(route('invitations.show', ['token' => $token]))->assertForbidden();
});

// ── The door is never bounced (AC-SESSION-4) ───────────────────────────────

it('AC-SESSION-4: an SSO session past its re-validation window still checks people in from the scanner (JSON POST)', function (): void {
    nexoSsoFakeProvider();
    $owner = User::factory()->create();
    $event = Event::factory()->for($owner, 'organizer')->create();
    $out = app(EventRegistrar::class)->register($event, 'Juan', 'juan@example.com');
    $session = nexoSsoSignedIn($this, $owner, checkedAgo: 3 * 60 * 60);

    $this->withSession($session)
        ->postJson(route('events.checkin', $event), ['token' => $out['token']], ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertOk()
        ->assertJson(['result' => 'ok', 'name' => 'Juan']);

    expect($out['ticket']->fresh()->status)->toBe(TicketStatus::CheckedIn);

    // The manual check-in from the list is a form POST: never bounced either.
    $other = app(EventRegistrar::class)->register($event, 'Ana', 'ana@example.com');
    $this->post(route('tickets.checkin', $other['ticket']))->assertRedirect();
    expect($other['ticket']->fresh()->status)->toBe(TicketStatus::CheckedIn);

    // Only a page navigation re-validates (and comes back to the door).
    $this->get(route('events.scan', $event))
        ->assertRedirect(route('nexo-sso.recheck', ['to' => "/app/eventos/{$event->id}/escanear"]));
});

it('AC-SESSION-4: a session Nexo ID ended answers the scanner 401 signed_out, and the ticket stays valid', function (): void {
    $owner = User::factory()->create();
    $event = Event::factory()->for($owner, 'organizer')->create();
    $out = app(EventRegistrar::class)->register($event, 'Juan', 'juan@example.com');
    $session = nexoSsoSignedIn($this, $owner);
    app(NexoSsoRevocations::class)->revoke('nexo-sid-0001', null);

    $this->withSession($session)
        ->postJson(route('events.checkin', $event), ['token' => $out['token']], ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertUnauthorized()
        ->assertExactJson(['reason' => 'signed_out']);

    $this->assertGuest();
    expect($out['ticket']->fresh()->status)->toBe(TicketStatus::Valid);
});

it('AC-SESSION-4: in sso mode an old password session at the door gets 401 too, never a redirect page', function (): void {
    nexoSessionSsoMode();
    $owner = User::factory()->create();
    $event = Event::factory()->for($owner, 'organizer')->create();
    $out = app(EventRegistrar::class)->register($event, 'Juan', 'juan@example.com');

    // Signed in before the deploy, with the local password: no nexo_sso.* keys.
    $this->actingAs($owner)
        ->postJson(route('events.checkin', $event), ['token' => $out['token']], ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertUnauthorized()
        ->assertExactJson(['reason' => 'signed_out']);

    expect($out['ticket']->fresh()->status)->toBe(TicketStatus::Valid);
});

// ── The Integration API never sees the session (AC-SESSION-5) ──────────────

it('AC-SESSION-5: the Integration API answers its token in sso mode, with no session, cookie or bounce', function (): void {
    nexoSessionSsoMode();
    config(['nexo.api.enabled' => true]);
    $owner = User::factory()->create();
    $event = Event::factory()->for($owner, 'organizer')->create(['external_ref' => 'herreria-1']);
    $raw = apiToken($owner);

    $response = $this->getJson("/api/v1/events/{$event->id}", bearer($raw))->assertOk();
    expect($response->headers->getCookies())->toBe([]);

    $this->getJson('/api/v1/me', bearer($raw))->assertOk();
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

// ── The revocations are pruned (AC-SESSION-9) ──────────────────────────────

it('AC-SESSION-9: the back-channel revocations are pruned daily by the scheduler, inline', function (): void {
    // Inline (Schedule::call + Artisan::call) like every task here: production
    // disables proc_open, so a Schedule::command subprocess would die unseen and
    // the table would only grow.
    $prune = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => $event->description === 'nexo-sso-prune');

    expect($prune)->toHaveCount(1)
        ->and($prune->first()->expression)->toBe('0 0 * * *')
        ->and(array_keys(Artisan::all()))->toContain('nexo-sso:prune');
});
