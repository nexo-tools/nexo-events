<?php

use App\Models\Event;
use App\Services\EventRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Nexo Events' own silent-SSO guard. The template suite proves the middleware
 * MECHANICS on throwaway probe routes; this file proves the CONFIGURATION —
 * that this tool's real attendee surfaces are in `silent_excluded` and stay
 * there. Attendees never have accounts (ADR-003), so bouncing one through Nexo
 * ID buys nothing and can only cost: the ticket page is opened at the venue
 * door, often on a bad connection.
 *
 * The last test is the deliberate counterpart: it proves the middleware really
 * is active on this app, so the green above means "excluded", not "inert".
 */
beforeEach(function (): void {
    config([
        'nexo-sso.enabled' => true,
        'nexo-sso.silent' => true,
        'nexo-sso.issuer' => 'https://nexoid.test',
        'nexo-sso.client_id' => '11111111-2222-3333-4444-555555555555',
    ]);
});

/**
 * A guest browser already carrying this app's session cookie — the only kind
 * the silent middleware ever acts on. Named for this file rather than shared:
 * Pest loads every test file into one process, so reusing the template suite's
 * helper name would fatal on redeclaration.
 *
 * @return array<string, string>
 */
function nexoEventsReturningGuest(): array
{
    return [(string) config('session.cookie') => 'previously-issued'];
}

test('AC-SILENT-EVENTS-1: the public event page never hands an attendee to Nexo ID', function (): void {
    $event = Event::factory()->create();

    $this->withCookies(nexoEventsReturningGuest())
        ->get(route('public.event', $event))
        ->assertOk()
        ->assertSessionMissing('nexo_sso.silent_attempted');
});

test('AC-SILENT-EVENTS-2: the attendee ticket page never bounces — a redirect here strands someone at the door', function (): void {
    $event = Event::factory()->create();
    $issued = app(EventRegistrar::class)->register($event, 'Ana', 'ana@example.com');

    $this->withCookies(nexoEventsReturningGuest())
        ->get(route('ticket.show', ['token' => $issued['token']]))
        ->assertOk()
        ->assertSessionMissing('nexo_sso.silent_attempted');
});

test('AC-SILENT-EVENTS-3: the health endpoint never bounces (uptime monitors, deploy smoke checks)', function (): void {
    $this->withCookies(nexoEventsReturningGuest())
        ->get('/up')
        ->assertOk()
        ->assertSessionMissing('nexo_sso.silent_attempted');
});

test('AC-SILENT-EVENTS-4: an organizer-facing guest page DOES attempt, so the exclusions above are what protect attendees', function (): void {
    $this->withCookies(nexoEventsReturningGuest())
        ->get(route('home'))
        ->assertRedirect(route('nexo-sso.silent'));
});
