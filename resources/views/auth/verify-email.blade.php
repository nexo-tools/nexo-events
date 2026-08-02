<x-guest-layout :auth-card="true" :noindex="true">
    <h1 class="mb-2 text-xl font-semibold">{{ __('Verify your email') }}</h1>

    <p class="mb-4 text-sm text-muted">
        {{ __('We sent a link to :email. Open it to publish your events; in the meantime you can create and edit drafts.', ['email' => auth()->user()->email]) }}
    </p>

    @if (session('status'))
        <p class="nexo-flash mb-4" role="status">{{ session('status') }}</p>
    @endif

    <form method="POST" action="{{ route('verification.send') }}">
        @csrf
        <x-button>{{ __('Resend the link') }}</x-button>
    </form>

    <p class="mt-6 text-sm">
        <a href="{{ route('dashboard') }}" class="text-link underline">{{ __('Back to the dashboard') }}</a>
    </p>
</x-guest-layout>
