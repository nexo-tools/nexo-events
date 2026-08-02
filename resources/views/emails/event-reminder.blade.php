{{--
    Pre-event reminder. No QR and no button to a ticket: the raw token is not
    stored (only its hash), so the code cannot be rebuilt without rotating it —
    and rotating would kill the ticket already saved in that person's inbox or
    photo gallery. What this mail gives instead is the practical thing: when and
    where, and the page where a lost ticket can be resent.
--}}
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
        __('Issued to') => $ticket->attendee_name,
    ]" />

    <p class="nexo-muted" style="margin:16px 0 4px; font-size:13px; line-height:1.6; color:#71717a;">
        {{ __('If you cannot find your ticket, ask for it again from the event page:') }}
    </p>
    <x-nexo-mail::code>{{ $eventUrl }}</x-nexo-mail::code>
</x-nexo-mail::layout>
