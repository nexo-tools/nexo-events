{{-- Sent by the tool where the link happened, never by the identity provider. --}}
<x-nexo-mail::layout :title="__('Nexo ID was linked to your account')" :preheader="__('Nexo ID was linked to your account')">
    <h1 class="nexo-ink" style="margin:0 0 16px; font-size:20px; line-height:1.3; font-weight:700; color:#18181b;">
        {{ __('Nexo ID was linked to your account') }}
    </h1>

    {{-- The password keeps working only in local mode: in transition and sso a
         linked account signs in with Nexo ID alone (NexoAuthMode). --}}
    @php($passwords = \App\Services\NexoSso\NexoAuthMode::isLocal())
    @php($explicit = ($how ?? 'email') === \App\Services\NexoSso\NexoSsoUserResolver::EXPLICIT)
    <p style="margin:0 0 20px; font-size:15px; line-height:1.6;">
        {{ $passwords
            ? __('From now on you can sign in here with your Nexo ID. Your password keeps working — nothing else changed.')
            : __('From now on you sign in here with your Nexo ID — nothing else changed.') }}
    </p>

    <p class="nexo-muted nexo-rule" style="margin:20px 0 0; padding-top:16px; border-top:1px solid #e4e4e7; font-size:12px; line-height:1.6; color:#a1a1aa;">
        @if ($passwords)
            {{ $explicit
                ? __('If you did not do this, change your password and write to us: someone who knew your password signed in here and linked the account.')
                : __('If you did not do this, change your password and write to us: your email address was verified by the identity provider, so someone with access to it linked the account.') }}
        @else
            {{ $explicit
                ? __('If you did not do this, write to us: someone who knew your password signed in here and linked the account.')
                : __('If you did not do this, write to us: your email address was verified by the identity provider, so someone with access to it linked the account.') }}
        @endif
    </p>
</x-nexo-mail::layout>
