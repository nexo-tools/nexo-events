{{-- Reviewing a collaborator invitation (ADR-011). One screen, four states:
     ok (accept), expired (410), wrong_email (403), unverified_account. --}}
<x-app-layout :title="__('Invitation to manage tickets')">
    <h1 class="mb-1 text-xl font-bold">{{ __('Invitation to manage tickets') }}</h1>

    @if ($state === 'ok')
        <p class="mb-4 text-sm text-muted">
            {{ __(':name invited you to manage the tickets of :event', ['name' => $collaborator->event->organizer->name, 'event' => $collaborator->event->title]) }}
        </p>

        <dl class="mb-4 space-y-2 rounded-lg border border-line p-3 text-sm">
            <div><dt class="text-xs text-muted">{{ __('Event') }}</dt><dd class="font-medium">{{ $collaborator->event->title }}</dd></div>
            <div><dt class="text-xs text-muted">{{ __('When') }}</dt><dd>{{ $collaborator->event->starts_at->translatedFormat(__('app.datetime')) }}</dd></div>
        </dl>

        <p class="mb-2 text-sm">{{ __('You will be able to:') }}</p>
        <ul class="mb-4 list-disc space-y-1 ps-5 text-sm">
            <li>{{ __('See who registered (names and status, not their emails)') }}</li>
            <li>{{ __('Change the capacity, close and reopen registration') }}</li>
            <li>{{ __('Check people in at the door and release unpaid spots') }}</li>
        </ul>
        <p class="mb-4 text-xs text-muted">{{ __('You will not be able to edit or cancel the event. Your access ends a week after the event.') }}</p>

        <form method="POST" action="{{ route('invitations.accept', ['token' => $token]) }}">
            @csrf
            <x-button>{{ __('Accept') }}</x-button>
        </form>
    @elseif ($state === 'expired')
        <p class="nexo-flash nexo-flash--warning mt-4" role="alert">
            {{ __('This invitation has expired. Ask whoever invited you to send it again.') }}
        </p>
    @elseif ($state === 'wrong_email')
        <p class="nexo-flash nexo-flash--danger mt-4" role="alert">
            {{ __('This invitation is for another email address. Sign out and sign in (or create your account) with the address it was sent to.') }}
        </p>
        <form method="POST" action="{{ config('nexo-sso.enabled') ? route('nexo-sso.logout') : route('logout') }}" class="mt-4">
            @csrf
            <x-button variant="secondary" :block="false">{{ __('Sign out') }}</x-button>
        </form>
    @else
        <p class="nexo-flash nexo-flash--warning mt-4" role="alert">
            <span>
                {{ __('Verify your email to accept the invitation. Once verified, you come back here.') }}
            </span>
        </p>
        <form method="POST" action="{{ route('verification.send') }}" class="mt-4">
            @csrf
            <x-button variant="secondary" :block="false">{{ __('Resend the link') }}</x-button>
        </form>
    @endif

    <a href="{{ route('dashboard') }}" class="mt-6 block text-sm text-muted hover:underline">{{ __('Back to the dashboard') }}</a>
</x-app-layout>
