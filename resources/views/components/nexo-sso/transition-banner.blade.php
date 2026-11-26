{{-- nexo-sso-client: «Link my account». During the `transition` auth mode,
     tells a person who signed in with a local password that the password door
     closes, says that a Nexo ID with another email works too, and offers «Link
     my account» — a POST to nexo-sso.link, which goes through Nexo ID and joins
     this account to whichever Nexo ID they sign in with. It also shows, once,
     the outcome of a link (joined, or why not) in any auth mode. Renders
     nothing in any other case. Place it at the top of the tool's panel (extra
     classes merge in):

       <x-nexo-sso.transition-banner class="mt-4" /> --}}
@php
    $request = request();
    $on = config('nexo-sso.enabled') && $request->hasSession();
    $notice = $on ? \App\Services\NexoSso\NexoSsoSession::notice($request) : null;
    $offer = $on
        && auth()->check()
        && \App\Services\NexoSso\NexoAuthMode::isTransition()
        && ! \App\Services\NexoSso\NexoSsoSession::isSso($request);
    $cutover = $offer ? \App\Services\NexoSso\NexoAuthMode::cutoverLabel() : null;
@endphp
@if ($notice !== null || $offer)
    <div {{ $attributes->merge(['class' => 'rounded-md border border-line bg-surface-raised px-4 py-3 text-sm text-ink']) }} data-nexo-sso-transition-banner>
        @if ($notice !== null && $notice['kind'] === 'linked')
            <p role="status" data-nexo-sso-linked>{{ __('Your account is now linked to Nexo ID. From now on, sign in with Nexo ID.') }}</p>
        @elseif ($notice !== null)
            <p role="alert" class="text-danger" data-nexo-sso-link-error>{{ $notice['message'] }}</p>
        @endif

        @if ($offer)
            <div @class(['flex flex-wrap items-center gap-x-4 gap-y-3', 'mt-3' => $notice !== null])>
                <p class="min-w-0 flex-1 basis-64">
                    {{ $cutover !== null ? __('From :date, sign-in is only with Nexo ID.', ['date' => $cutover]) : __('Soon, sign-in will be only with Nexo ID.') }}
                    {{ __('You can link this account to a Nexo ID with a different email.') }}
                </p>
                <form method="POST" action="{{ route('nexo-sso.link') }}" data-nexo-sso-link-form>
                    @csrf
                    <input type="hidden" name="to" value="{{ \App\Services\NexoSso\NexoSsoRequest::currentPath($request) }}">
                    <button type="submit" class="nexo-btn nexo-btn--primary">{{ __('Link my account') }}</button>
                </form>
            </div>
        @endif
    </div>
@endif
