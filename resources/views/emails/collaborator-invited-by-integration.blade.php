{{--
    To the owner: a connected system invited a collaborator (ADR-011 §7). Says
    which system, whom, to what, and where to take the access away.
--}}
<x-nexo-mail::layout
    :title="__('A connected system invited a collaborator')"
    :preheader="__(':system invited :email to manage :event', ['system' => $tokenName, 'email' => $collaborator->email, 'event' => $event->title])">

    <h1 class="nexo-ink" style="margin:0 0 16px; font-size:20px; line-height:1.3; font-weight:700; color:#18181b;">
        {{ __('A connected system invited a collaborator') }}
    </h1>

    <x-nexo-mail::panel :rows="[
        __('Connected system') => $tokenName,
        __('Invited') => $collaborator->email,
        __('Event') => $event->title,
    ]" />

    <p style="margin:0 0 20px; font-size:15px; line-height:1.6;">
        {{ __('Once they accept with an account using that email, they will see the names of the people registered for this event (not their emails) and can check them in at the door.') }}
    </p>

    <x-nexo-mail::button :url="$manageUrl">{{ __('Review collaborators') }}</x-nexo-mail::button>

    <p class="nexo-muted nexo-rule" style="margin:24px 0 0; padding-top:16px; border-top:1px solid #e4e4e7; font-size:12px; line-height:1.6; color:#a1a1aa;">
        {{ __('If you did not expect this, remove the access from the event and revoke that system\'s token under Integrations.') }}
    </p>
</x-nexo-mail::layout>
