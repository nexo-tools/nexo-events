{{--
    Collaborator invitation (ADR-011). Says who invites, to what, what the
    person will be able to do, until when the link works, and the one rule that
    trips people: the account has to use this same email address.
--}}
<x-nexo-mail::layout
    :title="__('Invitation to manage tickets')"
    :preheader="__(':name invited you to manage the tickets of :event', ['name' => $inviter, 'event' => $event->title])">

    <h1 class="nexo-ink" style="margin:0 0 16px; font-size:20px; line-height:1.3; font-weight:700; color:#18181b;">
        {{ __(':name invited you to manage the tickets of :event', ['name' => $inviter, 'event' => $event->title]) }}
    </h1>

    <x-nexo-mail::panel :rows="[
        __('Event') => $event->title,
        __('When') => $event->starts_at->translatedFormat(__('app.datetime')),
        __('Valid until') => $collaborator->expires_at->translatedFormat(__('app.datetime')),
    ]" />

    <p style="margin:0 0 20px; font-size:15px; line-height:1.6;">
        {{ __('You will be able to see who registered, change the capacity, close and reopen registration, and check people in at the door.') }}
    </p>

    <x-nexo-mail::button :url="$inviteUrl">{{ __('Review the invitation') }}</x-nexo-mail::button>

    <p class="nexo-muted" style="margin:16px 0 4px; font-size:13px; line-height:1.6; color:#71717a;">
        {{ __('If you do not have an account yet, create it with this same email address: the invitation only works for it.') }}
    </p>
    <x-nexo-mail::code>{{ $inviteUrl }}</x-nexo-mail::code>

    <p class="nexo-muted nexo-rule" style="margin:24px 0 0; padding-top:16px; border-top:1px solid #e4e4e7; font-size:12px; line-height:1.6; color:#a1a1aa;">
        {{ __('If you were not expecting this, you can ignore this email: nothing happens unless you accept.') }}
    </p>
</x-nexo-mail::layout>
