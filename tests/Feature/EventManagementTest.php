<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;

it('lets an organizer create a draft event with a slug', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('events.store'), [
        'title' => 'Mi Evento',
        'starts_at' => now()->addWeek()->format('Y-m-d\TH:i'),
    ]);

    $event = Event::firstOrFail();
    $response->assertRedirect(route('events.edit', $event));
    expect($event->organizer_id)->toBe($user->id)
        ->and($event->status)->toBe(EventStatus::Draft)
        ->and($event->slug)->not->toBeEmpty();
});

it('publishes and then closes registration', function () {
    $user = User::factory()->create();
    $event = Event::factory()->draft()->for($user, 'organizer')->create();

    $this->actingAs($user)->post(route('events.publish', $event))->assertRedirect();
    expect($event->fresh()->status)->toBe(EventStatus::Published);

    $this->actingAs($user)->post(route('events.close', $event))->assertRedirect();
    expect($event->fresh()->status)->toBe(EventStatus::Closed);
});

it('forbids a non-owner from editing an event', function () {
    $event = Event::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('events.edit', $event))
        ->assertForbidden();
});

it('404s the public page of a draft or killed event', function () {
    $draft = Event::factory()->draft()->create();
    $this->get(route('public.event', $draft))->assertNotFound();
});

it('shows the public page of a published event', function () {
    $event = Event::factory()->create(['title' => 'Fiesta Nexo']);
    $this->get(route('public.event', $event))->assertOk()->assertSee('Fiesta Nexo');
});
