<?php

use App\Enums\TicketStatus;
use App\Models\Checkin;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\User;
use App\Services\EventRegistrar;
use App\Services\TicketCheckin;

it('checks in a valid ticket by token', function () {
    $event = Event::factory()->create();
    $out = app(EventRegistrar::class)->register($event, 'Juan', 'juan@example.com');

    $res = app(TicketCheckin::class)->checkInByToken($out['token']);

    expect($res['result'])->toBe(TicketCheckin::OK)
        ->and($out['ticket']->fresh()->status)->toBe(TicketStatus::CheckedIn);
});

it('resolves a double scan to a single entry (atomic double-scan guard)', function () {
    $event = Event::factory()->create();
    $out = app(EventRegistrar::class)->register($event, 'Juan', 'juan@example.com');
    $checkin = app(TicketCheckin::class);

    $first = $checkin->checkInByToken($out['token']);
    $second = $checkin->checkInByToken($out['token']);

    expect($first['result'])->toBe(TicketCheckin::OK)
        ->and($second['result'])->toBe(TicketCheckin::ALREADY)
        ->and(Checkin::count())->toBe(1);
});

it('the UNIQUE(ticket_id) constraint blocks a concurrent second check-in', function () {
    // Model the racer: a check-in row already exists (the other scan won). The
    // service must not create a second one — it must report ALREADY.
    $event = Event::factory()->create();
    $out = app(EventRegistrar::class)->register($event, 'Juan', 'juan@example.com');
    $ticket = $out['ticket'];

    Checkin::create(['ticket_id' => $ticket->id, 'checked_at' => now()]);

    $res = app(TicketCheckin::class)->checkInTicket($ticket);

    expect($res['result'])->toBe(TicketCheckin::ALREADY)
        ->and(Checkin::where('ticket_id', $ticket->id)->count())->toBe(1);
});

it('rejects an unknown token and a revoked ticket', function () {
    $event = Event::factory()->create();
    $checkin = app(TicketCheckin::class);

    expect($checkin->checkInByToken('does-not-exist')['result'])->toBe(TicketCheckin::UNKNOWN);

    $revoked = Ticket::factory()->for($event)->create(['status' => TicketStatus::Revoked]);
    expect($checkin->checkInTicket($revoked)['result'])->toBe(TicketCheckin::REVOKED);
});

it('lets the organizer scan a token at their event door', function () {
    $user = User::factory()->create();
    $event = Event::factory()->for($user, 'organizer')->create();
    $out = app(EventRegistrar::class)->register($event, 'Juan', 'juan@example.com');

    $this->actingAs($user)
        ->post(route('events.checkin', $event), ['token' => $out['token']])
        ->assertRedirect();

    expect($out['ticket']->fresh()->status)->toBe(TicketStatus::CheckedIn);
});

it('forbids scanning at another organizer’s event', function () {
    $event = Event::factory()->create(); // owned by a different organizer

    $this->actingAs(User::factory()->create())
        ->post(route('events.checkin', $event), ['token' => 'x'])
        ->assertForbidden();
});
