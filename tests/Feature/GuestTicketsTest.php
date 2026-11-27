<?php

use App\Enums\TicketStatus;
use App\Models\Event;
use App\Models\EventCollaborator;
use App\Models\User;
use App\Services\EventRegistrar;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/Api/ApiTestSupport.php';

/**
 * «Invitado» — SPEC-event-panel AC-PANEL-11 (Alvaro, 2026-11-27): people register as
 * usual and the organizer marks some of them as guests from the list. A guest takes
 * no room of the capacity; the door scans them like anyone else; nobody is mailed.
 */
beforeEach(function (): void {
    Mail::fake();
});

/** Registers $name on $event and returns the outcome (ticket + token). */
function guestRegister(Event $event, string $name): array
{
    return app(EventRegistrar::class)->register($event->fresh(), $name, strtolower($name).'@example.com');
}

it('AC-PANEL-11: marking a ticket as a guest gives its spot back to the public, and the list and summary count guests apart', function (): void {
    $event = Event::factory()->withCapacity(1)->create();
    $ana = guestRegister($event, 'Ana');
    expect($event->fresh()->isSoldOut())->toBeTrue();

    $this->actingAs($event->organizer)->post(route('tickets.guest', $ana['ticket']), ['guest' => '1'])
        ->assertRedirect()->assertSessionHas('status');

    expect($ana['ticket']->fresh()->is_guest)->toBeTrue()
        ->and($event->fresh()->isSoldOut())->toBeFalse()
        ->and($event->fresh()->takenSpotsCount())->toBe(0)
        ->and($event->fresh()->issuedTicketsCount())->toBe(1) // still issued: the guest gets the event's mails
        ->and($event->fresh()->guestTicketsCount())->toBe(1)
        ->and(guestRegister($event, 'Beto')['result'])->toBe(EventRegistrar::OK)
        ->and($event->fresh()->isSoldOut())->toBeTrue();

    $list = (string) $this->actingAs($event->organizer)->get(route('events.registrations', $event))->assertOk()->getContent();
    expect($list)->toContain('data-ticket-guest>Invitado</span>')
        ->and($list)->toContain('data-guests-count>(1 invitado)</span>')
        ->and($list)->toContain('Quitar de invitados')
        ->and($list)->toContain('Marcar como invitado');

    $this->actingAs($event->organizer)->get(route('events.manage', $event))
        ->assertOk()
        ->assertSee('data-guests>+ 1 invitado</p>', false);

    Mail::assertNothingQueued();
    Mail::assertNothingSent();
});

it('AC-PANEL-11: counting a guest again needs room, and gets it once the capacity grows', function (): void {
    $event = Event::factory()->withCapacity(1)->create();
    $ana = guestRegister($event, 'Ana');
    $this->actingAs($event->organizer)->post(route('tickets.guest', $ana['ticket']), ['guest' => '1']);
    guestRegister($event, 'Beto'); // takes the only spot

    $this->actingAs($event->organizer)->post(route('tickets.guest', $ana['ticket']), ['guest' => '0'])
        ->assertRedirect()->assertSessionHasErrors('guest');
    expect($ana['ticket']->fresh()->is_guest)->toBeTrue();

    $event->forceFill(['capacity' => 2])->save();
    $this->actingAs($event->organizer)->post(route('tickets.guest', $ana['ticket']), ['guest' => '0'])
        ->assertSessionHasNoErrors();
    expect($ana['ticket']->fresh()->is_guest)->toBeFalse()
        ->and($event->fresh()->isSoldOut())->toBeTrue();
});

it('AC-PANEL-11: a released spot is never a guest one, and a released guest comes back even when the event is full', function (): void {
    $event = Event::factory()->withCapacity(1)->create();
    $ana = guestRegister($event, 'Ana');
    $this->actingAs($event->organizer)->post(route('tickets.release', $ana['ticket']));

    $this->actingAs($event->organizer)->post(route('tickets.guest', $ana['ticket']), ['guest' => '1'])
        ->assertSessionHasErrors('guest');
    expect($ana['ticket']->fresh()->is_guest)->toBeFalse();

    // A guest that gets released, then the spot is taken: the guest needs no room to come back.
    $caro = guestRegister($event, 'Caro');
    $this->actingAs($event->organizer)->post(route('tickets.guest', $caro['ticket']), ['guest' => '1']);
    $this->actingAs($event->organizer)->post(route('tickets.release', $caro['ticket']));
    guestRegister($event, 'Beto');
    expect($event->fresh()->isSoldOut())->toBeTrue();

    $list = (string) $this->actingAs($event->organizer)->get(route('events.registrations', $event))->getContent();
    expect($list)->toContain(route('tickets.restore', $caro['ticket']))
        ->and($list)->not->toContain(route('tickets.restore', $ana['ticket']))
        // A released row never reads «Invitado», though the flag stays for when it comes back.
        ->and($list)->not->toContain('data-ticket-guest');
    $this->actingAs($event->organizer)->post(route('tickets.restore', $caro['ticket']))->assertSessionHasNoErrors();
    expect($caro['ticket']->fresh()->status)->toBe(TicketStatus::Valid)
        ->and($caro['ticket']->fresh()->is_guest)->toBeTrue();
});

it('AC-PANEL-11: a collaborator marks guests too; a stranger cannot, and only 0 or 1 is accepted', function (): void {
    $event = Event::factory()->create();
    $band = User::factory()->create();
    EventCollaborator::factory()->for($event)->acceptedBy($band)->create();
    $ana = guestRegister($event, 'Ana');

    $this->actingAs(User::factory()->create())->post(route('tickets.guest', $ana['ticket']), ['guest' => '1'])->assertForbidden();
    $this->actingAs($band)->post(route('tickets.guest', $ana['ticket']), ['guest' => 'yes'])->assertSessionHasErrors('guest');
    expect($ana['ticket']->fresh()->is_guest)->toBeFalse();

    $this->actingAs($band)->post(route('tickets.guest', $ana['ticket']), ['guest' => '1'])->assertSessionHasNoErrors();
    expect($ana['ticket']->fresh()->is_guest)->toBeTrue();

    // A JSON 0/1 reads the same as the form's '0'/'1'.
    $this->actingAs($band)->postJson(route('tickets.guest', $ana['ticket']), ['guest' => 0]);
    expect($ana['ticket']->fresh()->is_guest)->toBeFalse();
    $this->actingAs($band)->postJson(route('tickets.guest', $ana['ticket']), ['guest' => 1]);
    expect($ana['ticket']->fresh()->is_guest)->toBeTrue();
});

it('AC-PANEL-11: the door scans a guest like anyone else, and registering can never make one', function (): void {
    $event = Event::factory()->create();
    $ana = guestRegister($event, 'Ana');
    $this->actingAs($event->organizer)->post(route('tickets.guest', $ana['ticket']), ['guest' => '1']);

    $this->actingAs($event->organizer)->postJson(route('events.checkin', $event), ['token' => $ana['token']])
        ->assertOk()->assertJsonPath('result', 'ok');
    expect($ana['ticket']->fresh()->is_guest)->toBeTrue();

    $this->post(route('public.register', $event), ['name' => 'Beto', 'email' => 'beto@example.com', 'is_guest' => '1']);
    expect($event->tickets()->where('attendee_email', 'beto@example.com')->value('is_guest'))->toBeFalse();
});

it('AC-PANEL-11: guests do not count for the capacity floor or sold out; the API still counts them as issued', function (): void {
    config(['nexo.api.enabled' => true]); // off on a fresh install (AC-API-1): the API part has to say so
    $event = Event::factory()->withCapacity(3)->create();
    guestRegister($event, 'Ana');
    guestRegister($event, 'Beto');
    $caro = guestRegister($event, 'Caro');
    $this->actingAs($event->organizer)->post(route('tickets.guest', $caro['ticket']), ['guest' => '1']);

    // Two tickets take room: the capacity goes down to 2, not below, and the message says why.
    $this->actingAs($event->organizer)->post(route('events.capacity', $event), ['capacity' => 1])
        ->assertSessionHasErrors(['capacity' => 'El cupo no puede ser menor que los 2 lugares ya ocupados (los invitados no cuentan).']);
    $this->actingAs($event->organizer)->post(route('events.capacity', $event), ['capacity' => 2])->assertSessionHasNoErrors();

    // La Herrería reads issued_tickets_count as «anotados» and as the people a cancellation mails: guests included.
    $this->getJson("/api/v1/events/{$event->id}", bearer(apiToken($event->organizer)))
        ->assertOk()
        ->assertJsonPath('capacity', 2)
        ->assertJsonPath('issued_tickets_count', 3)
        ->assertJsonPath('is_sold_out', true);
});
