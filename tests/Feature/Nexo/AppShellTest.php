<?php

use App\Models\Event;
use App\Models\User;

// Guardian: the authenticated area has a shell of its own.
//
// Why it exists: every organizer screen used to render inside x-guest-layout,
// the centred max-w-md card built for a login form, and logout existed only as
// a text link at the foot of the dashboard. From the event form or the door
// scanner there was no way out and no way back except chained "Volver" links,
// and nothing in the suite noticed.

it('renders the organizer screens in the app shell, not the auth card', function (): void {
    $organizer = User::factory()->create();
    $event = Event::factory()->for($organizer, 'organizer')->create();

    $screens = [
        route('dashboard'),
        route('events.create'),
        route('events.edit', $event),
        route('events.registrations', $event),
        route('events.scan', $event),
    ];

    foreach ($screens as $url) {
        $html = (string) $this->actingAs($organizer)->get($url)->assertOk()->getContent();

        expect(str_contains($html, 'max-w-3xl'))->toBeTrue("{$url} is not using the app shell container.");
        expect(str_contains($html, 'max-w-md'))->toBeFalse("{$url} is still rendering inside the auth card.");
    }
});

it('offers logout and a way back to the panel from every organizer screen', function (): void {
    $organizer = User::factory()->create();
    $event = Event::factory()->for($organizer, 'organizer')->create();

    $logout = config('nexo-sso.enabled') ? route('nexo-sso.logout') : route('logout');

    foreach ([route('events.create'), route('events.scan', $event)] as $url) {
        $html = (string) $this->actingAs($organizer)->get($url)->assertOk()->getContent();

        expect($html)->toContain($logout)
            ->and($html)->toContain(route('dashboard'));
    }
});

it('keeps the account menu out of the public pages', function (): void {
    $event = Event::factory()->create(['status' => 'published']);

    $html = (string) $this->get(route('public.event', $event))->assertOk()->getContent();

    expect(str_contains($html, __('Tu cuenta')))->toBeFalse('The account menu leaks into the public event page.');
});
