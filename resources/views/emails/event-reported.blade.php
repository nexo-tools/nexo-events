{{--
    Operator-facing: goes to whoever runs the instance, not to a user, so it is
    deliberately NOT translated (declared in the guardian\'s nexoOperatorMails()).
    It still wears the family layout — the operator is a person too, and reading
    a report on a phone at night is exactly when the shell matters.
--}}
<x-nexo-mail::layout
    title="Reported event"
    :preheader="'An event was reported: '.$event->title">

    <h1 class="nexo-ink" style="margin:0 0 16px; font-size:20px; line-height:1.3; font-weight:700; color:#18181b;">
        An event was reported
    </h1>

    <x-nexo-mail::panel :rows="[
        'Event' => $event->title,
        'Organizer' => $event->organizer->email,
        'Reports so far' => $totalReports,
        'Reporter' => $reporterEmail ?: 'not provided',
    ]" />

    <p style="margin:0 0 4px; font-size:14px; line-height:1.6;"><strong>Public page</strong></p>
    <x-nexo-mail::code><a href="{{ $publicUrl }}" style="color:#7c3aed;">{{ $publicUrl }}</a></x-nexo-mail::code>

    <p style="margin:0 0 4px; font-size:14px; line-height:1.6;"><strong>Reason</strong></p>
    <p class="nexo-panel nexo-ink" style="margin:0 0 20px; padding:12px 14px; background-color:#fafafa; border-radius:8px; font-size:14px; line-height:1.6; white-space:pre-line; color:#18181b;">{{ $reason }}</p>

    <p class="nexo-muted" style="margin:0 0 4px; font-size:13px; line-height:1.6; color:#71717a;">
        To take it down (reversible — the previous status is recorded):
    </p>
    <x-nexo-mail::code>php artisan events:kill {{ $event->slug }} --reason="..."</x-nexo-mail::code>

    <p class="nexo-muted" style="margin:0; font-size:12px; line-height:1.6; color:#a1a1aa;">
        Undo with <code>php artisan events:restore {{ $event->slug }}</code>
    </p>
</x-nexo-mail::layout>
