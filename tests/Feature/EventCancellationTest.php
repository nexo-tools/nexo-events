<?php

use App\Enums\EventStatus;
use App\Enums\TicketStatus;
use App\Mail\EventCancelled;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Cancelling an event used to be silent: the public page said cancelled and the
 * person holding a ticket found out at the door.
 */
it('AC-CANCEL-1: tells everyone holding a ticket when the organizer cancels', function (): void {
    Mail::fake();

    $organizer = User::factory()->create();
    $event = Event::factory()->for($organizer, 'organizer')->create(['status' => EventStatus::Published]);
    Ticket::factory()->count(3)->for($event)->create();

    $this->actingAs($organizer)
        ->post(route('events.cancel', $event))
        ->assertRedirect();

    Mail::assertQueuedCount(3);
    Mail::assertQueued(EventCancelled::class);
});

it('AC-CANCEL-2: writes each notice in the language that attendee registered in', function (): void {
    Mail::fake();

    $organizer = User::factory()->create();
    $event = Event::factory()->for($organizer, 'organizer')->create(['status' => EventStatus::Published]);
    $pt = Ticket::factory()->for($event)->create(['attendee_email' => 'ana@example.com', 'locale' => 'pt']);

    // The organizer cancels from their own session, days later. Without the
    // locale kept on the ticket, everyone would get the organizer's language.
    $this->actingAs($organizer)->post(route('events.cancel', $event));

    Mail::assertQueued(
        EventCancelled::class,
        fn (EventCancelled $mail): bool => $mail->hasTo($pt->attendee_email) && $mail->locale === 'pt'
    );
});

it('AC-CANCEL-3: cancelling an already cancelled event mails nobody a second time', function (): void {
    Mail::fake();

    $organizer = User::factory()->create();
    $event = Event::factory()->for($organizer, 'organizer')->create(['status' => EventStatus::Published]);
    Ticket::factory()->count(2)->for($event)->create();

    $this->actingAs($organizer)->post(route('events.cancel', $event));
    $this->actingAs($organizer)->post(route('events.cancel', $event));

    // The button stays on screen and a refresh re-posts: the second cancel is
    // a no-op, not a second round of mail.
    Mail::assertQueuedCount(2);
});

it('AC-CANCEL-4: leaves revoked tickets alone and mails nothing for an event with no attendees', function (): void {
    Mail::fake();

    $organizer = User::factory()->create();
    $empty = Event::factory()->for($organizer, 'organizer')->create(['status' => EventStatus::Published]);
    $this->actingAs($organizer)->post(route('events.cancel', $empty));
    Mail::assertNothingQueued();

    $withRevoked = Event::factory()->for($organizer, 'organizer')->create(['status' => EventStatus::Published]);
    Ticket::factory()->for($withRevoked)->create(['status' => TicketStatus::Revoked]);
    $this->actingAs($organizer)->post(route('events.cancel', $withRevoked));

    // A revoked ticket is one this organizer already took away: telling that
    // person the event is cancelled would be news about a ticket they lost.
    Mail::assertNothingQueued();
});

it('AC-CANCEL-5: only the organizer of the event can cancel it', function (): void {
    Mail::fake();

    $event = Event::factory()->create(['status' => EventStatus::Published]);
    Ticket::factory()->for($event)->create();

    $this->actingAs(User::factory()->create())
        ->post(route('events.cancel', $event))
        ->assertForbidden();

    Mail::assertNothingQueued();
});
