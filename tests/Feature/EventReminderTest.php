<?php

use App\Enums\EventStatus;
use App\Enums\TicketStatus;
use App\Mail\EventReminder;
use App\Models\Event;
use App\Models\Ticket;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

/**
 * The reminder v1 left out — and whose ghost was scheduled for months as a
 * command that did not exist.
 */
it('AC-REMIND-1: reminds everyone holding a valid ticket for an event inside the window', function (): void {
    Mail::fake();

    $event = Event::factory()->create(['status' => EventStatus::Published, 'starts_at' => now()->addHours(6)]);
    Ticket::factory()->count(2)->for($event)->create();

    Artisan::call('events:send-reminders');

    Mail::assertQueuedCount(2);
    Mail::assertQueued(EventReminder::class);
});

it('AC-REMIND-2: reminds each person once, however many times the sweep runs', function (): void {
    Mail::fake();

    $event = Event::factory()->create(['status' => EventStatus::Published, 'starts_at' => now()->addHours(3)]);
    Ticket::factory()->for($event)->create();

    Artisan::call('events:send-reminders');
    Artisan::call('events:send-reminders');
    Artisan::call('events:send-reminders');

    // The scheduler runs hourly: idempotency is the whole point of reminded_at.
    Mail::assertQueuedCount(1);
});

it('AC-REMIND-3: still reminds somebody who registers after the first sweep', function (): void {
    Mail::fake();

    $event = Event::factory()->create(['status' => EventStatus::Published, 'starts_at' => now()->addHours(5)]);
    Ticket::factory()->for($event)->create();

    Artisan::call('events:send-reminders');

    // Registering the same morning is the normal case, not the edge case.
    $late = Ticket::factory()->for($event)->create(['attendee_email' => 'late@example.com']);
    Artisan::call('events:send-reminders');

    Mail::assertQueuedCount(2);
    Mail::assertQueued(EventReminder::class, fn (EventReminder $mail): bool => $mail->hasTo('late@example.com'));
    expect($late->fresh()->reminded_at)->not->toBeNull();
});

it('AC-REMIND-4: leaves out events that are not happening, and tickets that were revoked', function (): void {
    Mail::fake();

    $cancelled = Event::factory()->create(['status' => EventStatus::Cancelled, 'starts_at' => now()->addHours(4)]);
    Ticket::factory()->for($cancelled)->create();

    $far = Event::factory()->create(['status' => EventStatus::Published, 'starts_at' => now()->addDays(9)]);
    Ticket::factory()->for($far)->create();

    $soon = Event::factory()->create(['status' => EventStatus::Published, 'starts_at' => now()->addHours(2)]);
    Ticket::factory()->for($soon)->create(['status' => TicketStatus::Revoked]);

    Artisan::call('events:send-reminders');

    // Reminding somebody of an event that was cancelled is worse than silence.
    Mail::assertNothingQueued();
});

it('AC-REMIND-5: writes the reminder in the language that person registered in', function (): void {
    Mail::fake();

    $event = Event::factory()->create(['status' => EventStatus::Published, 'starts_at' => now()->addHours(8)]);
    $ticket = Ticket::factory()->for($event)->create(['attendee_email' => 'ana@example.com', 'locale' => 'pt']);

    // A scheduled command has no request and no session: without the locale on
    // the ticket every reminder would go out in the app default.
    Artisan::call('events:send-reminders');

    Mail::assertQueued(
        EventReminder::class,
        fn (EventReminder $mail): bool => $mail->hasTo($ticket->attendee_email) && $mail->locale === 'pt'
    );
});

it('AC-REMIND-6: the window is a flag, and the scheduler owns the default', function (): void {
    Mail::fake();

    $event = Event::factory()->create(['status' => EventStatus::Published, 'starts_at' => now()->addHours(40)]);
    Ticket::factory()->for($event)->create();

    Artisan::call('events:send-reminders');
    Mail::assertNothingQueued();

    Artisan::call('events:send-reminders', ['--window' => 48]);
    Mail::assertQueuedCount(1);
});
