<x-guest-layout :noindex="true">
    <h1 class="mb-1 text-xl font-bold">{{ __('Your ticket') }}</h1>
    <p class="mb-4 text-sm text-muted">{{ $ticket->event->title }} · {{ $ticket->event->starts_at->translatedFormat(__('app.datetime')) }}</p>

    @if ($ticket->status === \App\Enums\TicketStatus::Revoked)
        {{-- A released spot (ADR-011 §6): the door already answers "revoked";
             the page says so instead of showing a QR that no longer works.
             No email is sent to the person. --}}
        <p class="nexo-flash nexo-flash--danger mb-4" role="alert" data-ticket-released>{{ __('The organizer released your spot: this ticket is no longer valid at the door. If you think this is a mistake, talk to whoever is organizing the event.') }}</p>

        <p class="text-center text-sm text-muted">{{ $ticket->attendee_name }}</p>
    @else
        {{-- The QR keeps its white quiet zone in dark mode too (`dark:bg-white`):
             QrSvg paints black modules, and scanners need that contrast — a QR on a
             dark surface is unreadable at the door. --}}
        <div class="mb-4 flex justify-center rounded-lg bg-white p-4 dark:bg-white" data-ticket-qr>
            {!! app(\App\Services\QrSvg::class)->forUrl($token) !!}
        </div>

        <p class="text-center text-sm text-muted">{{ $ticket->attendee_name }}</p>
        <p class="text-center text-xs text-muted">{{ __('Show this QR at the door.') }}</p>
    @endif

    {{-- What a ticket holder needs to get there (ADR-012). `venue` is public
         anyway; access_info is shown here and in the emails and nowhere else.
         Plain text, escaped, line breaks kept: no markup ever comes from these. --}}
    @if ($ticket->event->venue || $ticket->event->access_info || $ticket->event->payment_info)
        <dl class="mt-6 space-y-3 border-t border-line pt-4 text-sm" data-ticket-info>
            @if ($ticket->event->venue)
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted">{{ __('Where') }}</dt>
                    <dd class="text-ink">{{ $ticket->event->venue }}</dd>
                </div>
            @endif
            @if ($ticket->event->access_info)
                <div data-access-info>
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted">{{ __('Only for ticket holders') }}</dt>
                    <dd class="whitespace-pre-line text-ink">{{ $ticket->event->access_info }}</dd>
                </div>
            @endif
            @if ($ticket->event->payment_info)
                <div data-payment-info>
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted">{{ __('Entry') }}</dt>
                    <dd class="text-ink">{{ $ticket->event->payment_info }}</dd>
                </div>
            @endif
        </dl>
    @endif

    @if ($ticket->status->value === 'checked_in')
        <p class="nexo-flash mt-4 justify-center" role="status">{{ __('You have already checked in to this event.') }}</p>
    @endif
</x-guest-layout>
