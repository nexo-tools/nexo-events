{{--
    Pre-event reminder. No QR and no button to a ticket: the raw token is not
    stored (only its hash), so the code cannot be rebuilt without rotating it —
    and rotating would kill the ticket already saved in that person's inbox or
    photo gallery. What this mail gives instead is the practical thing: when and
    where, and the page where a lost ticket can be resent.
--}}
{{-- PLAN 10.14: the private address as a panel row, with one link to open it
     in Google Maps. Built as HtmlString so the panel prints the link; the
     address itself is escaped here. Null when the event has none, and the
     panel skips null rows. --}}
@php
    $addressRow = $event->access_address
        ? new \Illuminate\Support\HtmlString(
            e($event->access_address)
            .'<br><a href="'.e((string) $event->googleMapsUrl()).'" target="_blank" rel="noopener noreferrer" class="nexo-accent" style="color:#7c3aed; font-weight:600; text-decoration:underline;" data-maps="google">'
            .e(__('Open in Google Maps')).'</a>'
        )
        : null;
@endphp
<x-nexo-mail::layout
    :title="__('Reminder: :event', ['event' => $event->title])"
    :preheader="__('Your event is coming up.')">

    <h1 class="nexo-ink" style="margin:0 0 16px; font-size:20px; line-height:1.3; font-weight:700; color:#18181b;">
        {{ __('Your event is coming up') }}
    </h1>

    <p style="margin:0 0 20px; font-size:15px; line-height:1.6;">
        {{ __('A reminder so it does not catch you by surprise. Your ticket is in the email we sent when you registered — the QR in it is still the one the door reads.') }}
    </p>

    <x-nexo-mail::panel :rows="[
        __('Event') => $event->title,
        __('When') => $event->starts_at->translatedFormat(__('app.datetime')),
        __('Where') => $event->venue,
        __('Address') => $addressRow,
        __('Only for ticket holders') => $event->access_info,
        __('Entry') => $event->payment_info,
        __('Issued to') => $ticket->attendee_name,
    ]" />

    <p class="nexo-muted" style="margin:16px 0 4px; font-size:13px; line-height:1.6; color:#71717a;">
        {{ __('If you cannot find your ticket, ask for it again from the event page:') }}
    </p>
    <x-nexo-mail::code>{{ $eventUrl }}</x-nexo-mail::code>
</x-nexo-mail::layout>
