{{--
    Cancellation notice for whoever holds a ticket. Family layout, no button:
    there is nothing left to do — the honest shape of this mail is the fact, the
    details of what was cancelled, and no call to action.
--}}
<x-nexo-mail::layout
    :title="__('Event cancelled')"
    :preheader="__('Cancelled: :event', ['event' => $event->title])">

    <h1 class="nexo-ink" style="margin:0 0 16px; font-size:20px; line-height:1.3; font-weight:700; color:#18181b;">
        {{ __('The event was cancelled') }}
    </h1>

    <p style="margin:0 0 20px; font-size:15px; line-height:1.6;">
        {{ __('The organizer cancelled this event, so your ticket is no longer valid. You do not need to do anything.') }}
    </p>

    <x-nexo-mail::panel :rows="[
        __('Event') => $event->title,
        __('When') => $event->starts_at->translatedFormat(__('app.datetime')),
        __('Where') => $event->venue,
        __('Issued to') => $ticket->attendee_name,
    ]" />

    <p class="nexo-muted nexo-rule" style="margin:24px 0 0; padding-top:16px; border-top:1px solid #e4e4e7; font-size:12px; line-height:1.6; color:#a1a1aa;">
        {{ __('If you paid for this event outside the platform, contact the organizer directly: they collect and refund, not us.') }}
    </p>
</x-nexo-mail::layout>
