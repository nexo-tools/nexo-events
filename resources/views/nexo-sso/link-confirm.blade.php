{{-- nexo-sso-client: «Link this account?» (GET /auth/nexo/link/confirm,
     signed in, transition or local). A join whose Nexo ID email is not this
     account's own waits for its owner: both emails side by side, then
       «Link my account»   → joined; from now on, sign-in is with Nexo ID
       «Not now»           → nothing joined, the session goes on
     ADAPTATION POINT: the layout component (<x-panel-layout> is Nexo Short's)
     — wrap it in this tool's chrome, like signed-out. Everything inside the
     layout is shared. --}}
@php
    $heading = __('Link this account to Nexo ID?');
@endphp
<x-guest-layout :title="$heading" :noindex="true" :bare="true">
    <div class="mx-auto flex w-full max-w-md flex-col justify-center px-4 py-10 sm:py-16">
        <x-nexo-auth-card :title="$heading" data-nexo-sso-link-confirm>
            <dl class="space-y-3 text-sm">
                <div>
                    <dt class="text-muted">{{ __('Your :app account', ['app' => $app]) }}</dt>
                    <dd class="break-words font-medium text-ink" data-nexo-sso-local-email>{{ $localEmail }}</dd>
                </div>
                <div>
                    <dt class="text-muted">{{ __('Nexo ID account') }}</dt>
                    <dd class="break-words font-medium text-ink" data-nexo-sso-nexo-email>{{ $nexoEmail }}</dd>
                </div>
            </dl>

            <p class="mt-4 text-sm text-muted">
                {{ __('If that Nexo ID account is yours, link them: from then on you sign in here with Nexo ID. If it is not yours, choose «Not now».') }}
            </p>

            <form method="POST" action="{{ route('nexo-sso.link.confirm.store') }}" class="mt-6 flex flex-col gap-3">
                @csrf
                <button type="submit" name="choice" value="link" class="nexo-btn nexo-btn--primary w-full">{{ __('Link my account') }}</button>
                <button type="submit" name="choice" value="cancel" class="nexo-btn nexo-btn--ghost w-full">{{ __('Not now') }}</button>
            </form>
        </x-nexo-auth-card>
    </div>
</x-guest-layout>
