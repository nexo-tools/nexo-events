{{--
    Ticket email. Hand-written HTML with inline styles on purpose: mail clients
    strip <style> blocks and know nothing about the app's CSS or design tokens,
    so the violet is repeated literally here rather than pulled from
    nexo-tokens.css. (This file is allow-listed in NoHardcodedColorsTest.)
    Layout stays single-column and table-free-ish so it survives Outlook.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Your ticket') }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f4f5; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; color:#18181b;">
    <div style="display:none; max-height:0; overflow:hidden; opacity:0;">
        {{ __('Your ticket for :event', ['event' => $event->title]) }}
    </div>

    <div style="max-width:560px; margin:0 auto; padding:24px 16px;">
        <div style="background-color:#ffffff; border-radius:12px; padding:32px 24px; border:1px solid #e4e4e7;">

            <p style="margin:0 0 4px; font-size:13px; font-weight:600; color:#7c3aed; letter-spacing:0.04em; text-transform:uppercase;">
                {{ __('Your ticket') }}
            </p>

            <h1 style="margin:0 0 16px; font-size:22px; line-height:1.3; font-weight:700; color:#18181b;">
                {{ $event->title }}
            </h1>

            <p style="margin:0 0 24px; font-size:15px; line-height:1.6; color:#3f3f46;">
                {{ $event->starts_at->translatedFormat(__('app.datetime')) }}<br>
                @if ($event->venue)
                    {{ $event->venue }}<br>
                @endif
                <span style="color:#71717a;">{{ __('Issued to :name', ['name' => $ticket->attendee_name]) }}</span>
            </p>

            {{-- Embedded as a real attachment with a Content-ID: Gmail strips
                 data-URIs and Outlook does not render inline SVG (ADR-005 §7). --}}
            <div style="text-align:center; padding:20px 0; background-color:#ffffff;">
                <img src="{{ $message->embedData($qrPng, 'nexo-events-ticket.png', 'image/png') }}"
                     alt="{{ __('Your ticket QR code') }}"
                     width="220" height="220"
                     style="display:block; margin:0 auto; width:220px; height:220px;">
            </div>

            <p style="margin:8px 0 24px; text-align:center; font-size:14px; color:#52525b;">
                {{ __('Show this QR at the door.') }}
            </p>

            <div style="text-align:center; margin:0 0 24px;">
                <a href="{{ $ticketUrl }}"
                   style="display:inline-block; background-color:#7c3aed; color:#ffffff; text-decoration:none; font-size:15px; font-weight:600; padding:12px 24px; border-radius:8px;">
                    {{ __('View my ticket') }}
                </a>
            </div>

            {{-- The real fallback: many clients block images by default, and this
                 link opens the same QR the door already accepts. --}}
            <p style="margin:0; font-size:13px; line-height:1.6; color:#71717a;">
                {{ __('If you can\'t see the code, open your ticket from this link:') }}<br>
                <a href="{{ $ticketUrl }}" style="color:#7c3aed; word-break:break-all;">{{ $ticketUrl }}</a>
            </p>

            <p style="margin:24px 0 0; padding-top:16px; border-top:1px solid #e4e4e7; font-size:12px; line-height:1.6; color:#a1a1aa;">
                {{ __('Keep this email — it is your ticket. Don\'t share it: whoever has the code can get in instead of you.') }}
            </p>
        </div>

        <p style="margin:16px 0 0; text-align:center; font-size:12px; color:#a1a1aa;">
            {{ config('app.name') }}
        </p>
    </div>
</body>
</html>
