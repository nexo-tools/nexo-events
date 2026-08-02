{{--
    Ticket email — the one transactional template of v1 (ADR-005 §6): the
    registration confirmation IS the ticket.

    Since 2026-08-02 it renders inside the family layout (templates/nexo-mail),
    so the header, the footer, the dark-mode media query and the 600px shell are
    shared with every other mail of the ecosystem instead of being repeated by
    hand in each view. What stays here is what is specific to a ticket: the QR,
    the door warning, and the link that works when images do not.
--}}
<x-nexo-mail::layout
    :title="__('Your ticket')"
    :preheader="__('Your ticket for :event', ['event' => $event->title])">

    <p class="nexo-accent" style="margin:0 0 4px; font-size:13px; font-weight:600; color:#7c3aed; letter-spacing:0.04em; text-transform:uppercase;">
        {{ __('Your ticket') }}
    </p>

    <h1 class="nexo-ink" style="margin:0 0 16px; font-size:22px; line-height:1.3; font-weight:700; color:#18181b;">
        {{ $event->title }}
    </h1>

    <x-nexo-mail::panel :rows="[
        __('When') => $event->starts_at->translatedFormat(__('app.datetime')),
        __('Where') => $event->venue,
        __('Issued to') => $ticket->attendee_name,
    ]" />

    {{-- Embedded as a real attachment with a Content-ID: Gmail strips data-URIs
         and Outlook does not render inline SVG (ADR-005 §7). --}}
    <div style="text-align:center; padding:12px 0 4px;">
        <img src="{{ $message->embedData($qrPng, 'nexo-events-ticket.png', 'image/png') }}"
             alt="{{ __('Your ticket QR code') }}"
             width="220" height="220"
             style="display:block; margin:0 auto; width:220px; height:220px; background-color:#ffffff; border-radius:8px;">
    </div>

    <p class="nexo-muted" style="margin:8px 0 20px; text-align:center; font-size:14px; color:#52525b;">
        {{ __('Show this QR at the door.') }}
    </p>

    <x-nexo-mail::button :url="$ticketUrl">{{ __('View my ticket') }}</x-nexo-mail::button>

    {{-- The real fallback: many clients block images by default, and this link
         opens the same QR the door already accepts. --}}
    <p class="nexo-muted" style="margin:16px 0 4px; font-size:13px; line-height:1.6; color:#71717a;">
        {{ __('If you can\'t see the code, open your ticket from this link:') }}
    </p>
    <x-nexo-mail::code>{{ $ticketUrl }}</x-nexo-mail::code>

    <p class="nexo-muted nexo-rule" style="margin:24px 0 0; padding-top:16px; border-top:1px solid #e4e4e7; font-size:12px; line-height:1.6; color:#a1a1aa;">
        {{ __('Keep this email — it is your ticket. Don\'t share it: whoever has the code can get in instead of you.') }}
    </p>
</x-nexo-mail::layout>
