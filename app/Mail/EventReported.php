<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\EventReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the instance operator an event was reported (ADR-007 §3), with the one
 * command they will want next. Queued: a report must never fail because the
 * relay is slow, and the reporter should not wait on it either.
 *
 * Operator-facing, so it is intentionally not translated — it goes to whoever
 * runs the instance, not to a user.
 */
class EventReported extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public EventReport $report) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reported event: '.$this->report->event->title,
        );
    }

    public function content(): Content
    {
        $event = $this->report->event;

        return new Content(
            view: 'emails.event-reported',
            with: [
                'event' => $event,
                'reason' => $this->report->reason,
                'reporterEmail' => $this->report->reporter_email,
                'publicUrl' => route('public.event', $event),
                'totalReports' => $event->reports()->count(),
            ],
        );
    }
}
