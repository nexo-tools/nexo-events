{{-- nexo-sso-client: «First time in <App>?» (GET /auth/nexo/new-account,
     transition mode). A Nexo ID sign-in matched no account of this tool — not
     by Nexo ID, not by email — and waits in the session for 15 minutes:
       «Create my account»                              → a new account, signed in
       «I already have an account with another email»   → the password login; signing
                                                          in there offers to join that
                                                          account («Link this account?»)
     ADAPTATION POINT: the layout component (<x-panel-layout> is Nexo Short's)
     — wrap it in this tool's chrome, like signed-out. Everything inside the
     layout is shared. --}}
@php
    $heading = __('First time in :app?', ['app' => $app]);
@endphp
<x-guest-layout :title="$heading" :noindex="true" :bare="true">
    <div class="mx-auto flex w-full max-w-md flex-col justify-center px-4 py-10 sm:py-16">
        <x-nexo-auth-card :title="$heading" data-nexo-sso-new-account>
            <p class="text-sm text-muted">
                {{ __('You signed in to Nexo ID as :email, and no :app account uses that email yet.', ['email' => $email, 'app' => $app]) }}
            </p>

            <form method="POST" action="{{ route('nexo-sso.new-account.store') }}" class="mt-6 flex flex-col gap-3">
                @csrf
                <button type="submit" name="choice" value="create" class="nexo-btn nexo-btn--primary w-full">{{ __('Create my account') }}</button>
                <button type="submit" name="choice" value="existing" class="nexo-btn nexo-btn--ghost w-full">{{ __('I already have an account with another email') }}</button>
            </form>

            <p class="mt-4 text-sm text-muted">
                {{ __('If you already have one, sign in with its email and password within 15 minutes: then you can link it to this Nexo ID.') }}
            </p>
        </x-nexo-auth-card>
    </div>
</x-guest-layout>
