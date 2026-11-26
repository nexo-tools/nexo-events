<?php

use App\Enums\TicketStatus;
use App\Models\Event;
use App\Models\User;
use App\Services\EventRegistrar;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/Api/ApiTestSupport.php';

/**
 * The edit form's capacity floor (PLAN 10.21) — SPEC docs/specs/SPEC-event-panel.md.
 * The summary's capacity form already refused a number below the tickets
 * issued; the edit form, one tab away, took anything.
 */
beforeEach(function (): void {
    Mail::fake();
});

/** An owner's event with $tickets registrations and $capacity. */
function floorEvent(int $capacity, int $tickets): Event
{
    $event = Event::factory()->withCapacity($capacity)->create();

    foreach (range(1, $tickets) as $i) {
        app(EventRegistrar::class)->register($event, "Persona {$i}", "persona{$i}@example.com");
    }

    return $event;
}

/** What the edit form posts: every field, with the capacity of the test. */
function floorForm(Event $event, mixed $capacity): array
{
    return [
        'title' => $event->title,
        'starts_at' => $event->starts_at->format('Y-m-d\TH:i'),
        'capacity' => $capacity,
    ];
}

it('AC-PANEL-6: the edit form refuses a capacity below the tickets issued, with the summary\'s message', function (): void {
    $event = floorEvent(10, 3);

    $this->actingAs($event->organizer)->from(route('events.edit', $event))
        ->put(route('events.update', $event), floorForm($event, 2))
        ->assertRedirect(route('events.edit', $event))
        ->assertSessionHasErrors(['capacity' => __('The capacity cannot be lower than the :count tickets already issued.', ['count' => 3])]);

    expect($event->fresh()->capacity)->toBe(10);

    // Exactly the tickets out is fine (sold out), and so is "no limit".
    $this->actingAs($event->organizer)->put(route('events.update', $event), floorForm($event, 3))->assertSessionHasNoErrors();
    expect($event->fresh()->capacity)->toBe(3);

    $this->actingAs($event->organizer)->put(route('events.update', $event->fresh()), floorForm($event, ''))->assertSessionHasNoErrors();
    expect($event->fresh()->capacity)->toBeNull();
});

it('AC-PANEL-6: a released ticket does not count toward the floor', function (): void {
    $event = floorEvent(10, 3);
    $event->tickets()->latest('id')->first()->update(['status' => TicketStatus::Revoked]);

    $this->actingAs($event->organizer)->put(route('events.update', $event), floorForm($event, 2))->assertSessionHasNoErrors();
    expect($event->fresh()->capacity)->toBe(2);
});

it('AC-PANEL-6: an event whose stored capacity is already under the floor can still be edited', function (): void {
    // Older data: the edit form took any number before 10.21.
    $event = floorEvent(10, 3);
    $event->forceFill(['capacity' => 1])->save();

    $form = ['title' => 'Título nuevo'] + floorForm($event, 1);

    $this->actingAs($event->organizer)->put(route('events.update', $event), $form)->assertSessionHasNoErrors();
    expect($event->fresh()->title)->toBe('Título nuevo')->and($event->fresh()->capacity)->toBe(1);
});

it('AC-PANEL-6: a new event has no floor', function (): void {
    $this->actingAs(User::factory()->create())->post(route('events.store'), [
        'title' => 'Nuevo',
        'starts_at' => now()->addWeek()->format('Y-m-d\TH:i'),
        'capacity' => 1,
    ])->assertSessionHasNoErrors();

    expect(Event::sole()->capacity)->toBe(1);
});

it('AC-PANEL-6: the API applies the same floor on PATCH, and leaves the capacity alone when it is not sent', function (): void {
    config(['nexo.api.enabled' => true]);
    $event = floorEvent(10, 3);
    $headers = bearer(apiToken($event->organizer));

    $this->patchJson('/api/v1/events/'.$event->id, ['capacity' => 2], $headers)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['capacity' => __('The capacity cannot be lower than the :count tickets already issued.', ['count' => 3])]);
    expect($event->fresh()->capacity)->toBe(10);

    // What the first consumer sends after creation: content only, no capacity.
    $this->patchJson('/api/v1/events/'.$event->id, ['title' => 'Otro título'], $headers)->assertOk()->assertJsonPath('capacity', 10);

    $this->patchJson('/api/v1/events/'.$event->id, ['capacity' => 4], $headers)->assertOk()->assertJsonPath('capacity', 4);
});

it('AC-PANEL-6: somebody else\'s event still answers 404 on the API, never the floor of its tickets', function (): void {
    config(['nexo.api.enabled' => true]);
    $event = floorEvent(10, 3);
    $stranger = User::factory()->create();

    $this->patchJson('/api/v1/events/'.$event->id, ['capacity' => 1], bearer(apiToken($stranger)))
        ->assertNotFound()
        ->assertJsonMissingValidationErrors('capacity');

    expect($event->fresh()->capacity)->toBe(10);
});
