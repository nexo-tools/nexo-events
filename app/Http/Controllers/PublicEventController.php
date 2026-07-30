<?php

namespace App\Http\Controllers;

use App\Enums\EventStatus;
use App\Http\Requests\RegisterAttendeeRequest;
use App\Http\Requests\ResendTicketRequest;
use App\Mail\TicketIssued;
use App\Models\Event;
use App\Services\EventRegistrar;
use App\Services\EventViewCounter;
use App\Services\TicketResender;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PublicEventController extends Controller
{
    public function show(Request $request, Event $event, EventViewCounter $views): View
    {
        abort_unless($event->status->isPublic(), 404);

        $views->record($event, $request);

        return view('public.event.show', [
            'event' => $event,
            // Per-event metadata: without it every event shared on social would
            // carry the same generic title and description as the home page.
            'title' => $event->title,
            'description' => $this->seoDescription($event),
            'seoType' => 'article',
            'seoJsonLd' => $this->eventJsonLd($event),
        ]);
    }

    public function register(RegisterAttendeeRequest $request, Event $event, EventRegistrar $registrar): RedirectResponse
    {
        abort_unless($event->status->isPublic(), 404);

        $outcome = $registrar->register(
            $event,
            $request->string('name')->toString(),
            $request->string('email')->toString(),
        );

        if ($outcome['result'] === EventRegistrar::OK && $outcome['ticket'] !== null && $outcome['token'] !== null) {
            // Queued, so a slow or dead relay degrades to "the email is late",
            // never to "registration failed" (AC-EMAIL-5). The locale is pinned
            // now because the queue worker has no idea who registered or in what
            // language they were reading (AC-EMAIL-6).
            Mail::to($outcome['ticket']->attendee_email)
                ->locale(app()->getLocale())
                ->queue(new TicketIssued($outcome['ticket'], $outcome['token']));
        }

        return match ($outcome['result']) {
            EventRegistrar::OK => redirect()->route('ticket.show', ['token' => $outcome['token']]),
            EventRegistrar::DUPLICATE => back()->with('status', __('You are already registered with that email. Ask us to resend your ticket if you cannot find it.')),
            EventRegistrar::SOLD_OUT => back()->withErrors(['email' => __('This event is sold out.')]),
            default => back()->withErrors(['email' => __('Registration for this event is closed.')]),
        };
    }

    /** A one-line summary for search results and link previews. */
    private function seoDescription(Event $event): string
    {
        $when = $event->starts_at->translatedFormat(__('app.datetime'));
        $where = $event->venue ? ' · '.$event->venue : '';

        return Str::limit(
            trim($when.$where.' — '.(string) $event->description),
            160
        );
    }

    /**
     * schema.org/Event, so search engines and messaging apps can show the date
     * and venue rather than a bare link. `eventStatus` is emitted honestly:
     * a cancelled event must not keep advertising itself as scheduled.
     *
     * @return array<string, mixed>
     */
    private function eventJsonLd(Event $event): array
    {
        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            'name' => $event->title,
            'description' => (string) $event->description,
            'startDate' => $event->starts_at->toIso8601String(),
            // A cancelled event still has a public page (it has to tell people
            // it was cancelled), so this must not keep claiming "scheduled" —
            // that is the claim Google surfaces in results.
            'eventStatus' => $event->status === EventStatus::Cancelled
                ? 'https://schema.org/EventCancelled'
                : 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
            'url' => route('public.event', $event),
            'location' => $event->venue ? [
                '@type' => 'Place',
                'name' => $event->venue,
                'address' => $event->venue,
            ] : null,
            'organizer' => [
                '@type' => 'Organization',
                'name' => $event->organizer->name,
            ],
            'offers' => [
                '@type' => 'Offer',
                'price' => '0',
                'priceCurrency' => 'ARS',
                'availability' => $event->isSoldOut()
                    ? 'https://schema.org/SoldOut'
                    : 'https://schema.org/InStock',
                'url' => route('public.event', $event),
            ],
        ], fn ($value): bool => $value !== null);
    }

    /**
     * Re-deliver a lost ticket. Mints a new token and retires the old QR
     * (ADR-008) — the original is unrecoverable because only its hash is kept.
     */
    public function resend(ResendTicketRequest $request, Event $event, TicketResender $resender): RedirectResponse
    {
        abort_unless($event->status->isPublic(), 404);

        $resender->resend(
            $event,
            $request->string('email')->toString(),
            app()->getLocale(),
        );

        // Deliberately the same answer whether or not that address holds a
        // ticket: branching here would turn the page into an attendee-list
        // oracle for anyone with a browser (AC-RESEND-2).
        return back()->with('status', __('If that email has a ticket for this event, we have resent it. Check your inbox.'));
    }
}
