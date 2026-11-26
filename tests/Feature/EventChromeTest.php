<?php

// What the shared chrome v2.1 means on Nexo Events' own screens (PLAN 10.26,
// docs/specs/SPEC-nexo-session.md). The nexo-ui guardians in tests/Feature/Nexo/
// check the chrome on the pages every app shares; this file checks the ones only
// this app has: the public event page (D8), the event screens' tab titles and
// the landing's doors for a visitor.

use App\Models\Event;
use App\Models\EventCollaborator;
use App\Models\User;
use App\Support\NexoChrome;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'nexo-sso.enabled' => true,
        'nexo-sso.issuer' => 'https://nexoid.test',
        'nexo-sso.client_id' => '11111111-2222-3333-4444-555555555555',
    ]);
});

/** The <title> of a page. */
function eventChromeTitle(string $html): string
{
    preg_match('/<title>(.*?)<\/title>/s', $html, $m);

    return trim(html_entity_decode($m[1] ?? '', ENT_QUOTES));
}

// ── D8: the public event page (AC-SESSION-6) ───────────────────────────────

it('AC-SESSION-6: the public event page carries no tool header, only the public footer', function (): void {
    $event = Event::factory()->create();

    $html = (string) $this->get(route('public.event', $event))->assertOk()->getContent();

    expect(str_contains($html, 'data-nexo-header'))->toBeFalse('The attendee sees the tool header (D8).')
        ->and(str_contains($html, 'data-nexo-app-switcher'))->toBeFalse('The attendee sees the switcher of Nexo tools.')
        ->and(str_contains($html, 'data-nexo-account-menu'))->toBeFalse()
        ->and(str_contains($html, 'data-nexo-public-footer'))->toBeTrue('The public footer is missing.')
        ->and(str_contains($html, 'data-nexo-theme-toggle'))->toBeTrue()
        ->and(str_contains($html, '?lang=en'))->toBeTrue()
        ->and(str_contains($html, e(__('nexo.public_footer.made_with', ['app' => NexoChrome::brand()]))))->toBeTrue()
        ->and(str_contains($html, 'data-nexo-edit-chip'))->toBeFalse('A visitor sees «Editar».');
});

it('AC-SESSION-6: «Editar» only for the owner (to the editor) and a collaborator (to the summary)', function (): void {
    $event = Event::factory()->create();
    $band = User::factory()->create();
    EventCollaborator::factory()->for($event)->acceptedBy($band)->create();
    $stranger = User::factory()->create();

    $chip = fn (?User $user): ?string => preg_match(
        '/<a href="([^"]*)" class="nexo-chip" data-nexo-edit-chip/',
        (string) ($user ? $this->actingAs($user) : $this)->get(route('public.event', $event))->assertOk()->getContent(),
        $m,
    ) ? html_entity_decode($m[1]) : null;

    expect($chip($event->organizer))->toBe(route('events.edit', $event))
        ->and($chip($band))->toBe(route('events.manage', $event))
        ->and($chip($stranger))->toBeNull();

    // A collaborator whose access window closed (a week after the event) loses it too.
    $this->travelTo($event->starts_at->copy()->addDays(8));
    expect($chip($band))->toBeNull();
});

it('AC-SESSION-6: the registration form is the same form it always was', function (): void {
    $event = Event::factory()->create();

    $html = (string) $this->get(route('public.event', $event))->assertOk()->getContent();

    expect($html)->toContain('action="'.route('public.register', $event).'"')
        ->toContain('name="name"')
        ->toContain('name="email"')
        ->toContain('name="website"')
        ->toContain('action="'.route('public.resend', $event).'"')
        ->toContain('action="'.route('public.report', $event).'"');

    $this->post(route('public.register', $event), ['name' => 'Juan', 'email' => 'juan@example.com'])
        ->assertRedirect();
    expect($event->tickets()->where('attendee_email', 'juan@example.com')->exists())->toBeTrue();
});

it('AC-SESSION-6: an organizer with a session opening the page is never bounced to Nexo ID', function (): void {
    $event = Event::factory()->create();
    $this->actingAs($event->organizer)
        ->withSession(['nexo_sso.sub' => 'sub-1', 'nexo_sso.id_token' => 'x', 'nexo_sso.checked_at' => now()->subHours(3)->getTimestamp()])
        ->get(route('public.event', $event))
        ->assertOk();
});

// ── Tab titles of the event screens (AC-SESSION-7, H-nexoevents-16) ────────

it('AC-SESSION-7: every organizer screen names itself and the event in the tab', function (): void {
    $owner = User::factory()->create();
    $event = Event::factory()->for($owner, 'organizer')->create(['title' => 'Aniversario']);
    $this->actingAs($owner);

    $expected = [
        route('dashboard') => __('Your events').' — Nexo Events',
        route('events.create') => __('New event').' — Nexo Events',
        route('events.manage', $event) => __('Summary').' · Aniversario — Nexo Events',
        route('events.registrations', $event) => __('Registrations').' · Aniversario — Nexo Events',
        route('events.scan', $event) => __('Door').' · Aniversario — Nexo Events',
        route('events.edit', $event) => __('Edit').' · Aniversario — Nexo Events',
    ];

    foreach ($expected as $url => $title) {
        expect(eventChromeTitle((string) $this->get($url)->assertOk()->getContent()))->toBe($title);
    }
});

it('AC-SESSION-7: the auth screens and the landing follow the family format', function (): void {
    config(['nexo-sso.auth_mode' => 'local']);

    expect(eventChromeTitle((string) $this->get('/login')->getContent()))->toBe(__('Sign in').' — Nexo Events')
        ->and(eventChromeTitle((string) $this->get('/register')->getContent()))->toBe(__('Create account').' — Nexo Events')
        ->and(eventChromeTitle((string) $this->get('/')->getContent()))->toStartWith('Nexo Events — ');
});

// ── The landing's doors for a visitor (AC-SESSION-8, H-nexoevents-12) ──────

it('AC-SESSION-8: in sso mode a visitor\'s «Create an event» enters through Nexo ID to the new-event form', function (): void {
    config(['nexo-sso.auth_mode' => 'sso']);

    $html = (string) $this->get('/')->assertOk()->getContent();
    preg_match('/<div class="mt-8 flex flex-wrap gap-3" data-hero-actions>(.*?)<\/div>/s', $html, $hero);

    expect($hero[1] ?? '')->toContain(e(route('nexo-sso.enter', ['to' => '/app/eventos/nuevo'])))
        ->toContain('href="'.route('nexo-sso.enter').'"')
        ->not->toContain('href="'.route('register').'"')
        ->not->toContain('href="'.route('login').'"');
    expect(str_contains($html, 'href="'.route('register').'"'))->toBeFalse('The closing CTA still points at the closed local sign-up.');
});

it('AC-SESSION-8: in local mode (and standalone) the visitor\'s doors stay the local sign-up and sign-in', function (): void {
    config(['nexo-sso.auth_mode' => 'local']);
    $html = (string) $this->get('/')->assertOk()->getContent();
    expect($html)->toContain('href="'.route('register').'"')->toContain('href="'.route('login').'"');

    config(['nexo-sso.enabled' => false]);
    $html = (string) $this->get('/')->assertOk()->getContent();
    expect($html)->toContain('href="'.route('register').'"')->toContain('href="'.route('login').'"');
});

it('AC-SESSION-8: signed in, the hero leads into the panel whatever the mode', function (): void {
    config(['nexo-sso.auth_mode' => 'sso']);
    $this->actingAs(User::factory()->create())
        ->withSession(['nexo_sso.sub' => 'sub-1', 'nexo_sso.id_token' => 'x', 'nexo_sso.checked_at' => now()->getTimestamp()]);

    $html = (string) $this->get('/')->assertOk()->getContent();
    expect($html)->toContain('href="'.route('events.create').'"')->toContain('href="'.route('dashboard').'"');
});
