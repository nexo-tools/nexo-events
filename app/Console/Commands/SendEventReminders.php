<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EventStatus;
use App\Enums\TicketStatus;
use App\Mail\EventReminder;
use App\Models\Event;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Pre-event reminder. The gap the ecosystem audit named: an attendee reasonably
 * expects to hear from us before the day, and v1 shipped a scheduler entry for
 * a nexo:send-reminders command that never existed (routes/console.php once
 * documented its own removal).
 *
 * Runs hourly, sweeps the events starting inside the window, and marks each
 * ticket as reminded so the next sweep skips it: the idempotency lives on the
 * ticket, not the event, because people register the same morning — after the
 * first sweep already ran — and they deserve the reminder too.
 *
 * Cancelled and killed events are excluded: their attendees got (or will get)
 * the cancellation notice, and reminding someone of an event that is not
 * happening is worse than saying nothing.
 */
class SendEventReminders extends Command
{
    protected $signature = 'events:send-reminders {--window=24 : How many hours ahead to look}';

    protected $description = 'Email a reminder to everyone holding a valid ticket for an event starting soon';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('window'));
        $until = now()->addHours($hours);

        $events = Event::query()
            ->whereIn('status', [EventStatus::Published->value, EventStatus::Closed->value])
            ->whereBetween('starts_at', [now(), $until])
            ->get();

        $sent = 0;

        foreach ($events as $event) {
            $tickets = $event->tickets()
                ->where('status', '!=', TicketStatus::Revoked->value)
                ->whereNull('reminded_at')
                ->cursor();

            foreach ($tickets as $ticket) {
                Mail::to($ticket->attendee_email)
                    ->locale($ticket->locale ?: config('app.locale'))
                    ->queue(new EventReminder($ticket));

                // Marked before the queue runs on purpose: a worker that dies
                // mid-batch must not re-send to everybody on the next sweep.
                $ticket->forceFill(['reminded_at' => now()])->save();
                $sent++;
            }
        }

        $this->info("Reminders queued: {$sent} (events in the next {$hours}h: {$events->count()}).");

        return self::SUCCESS;
    }
}
