<x-guest-layout :title="$title" :description="$description" :seo-type="$seoType" :seo-json-ld="$seoJsonLd">
    <h1 class="mb-1 text-2xl font-bold">{{ $event->title }}</h1>
    <p class="mb-4 text-sm text-muted">
        {{ $event->starts_at->translatedFormat(__('app.datetime')) }}@if ($event->venue) · {{ $event->venue }}@endif
    </p>

    @if ($event->description)
        <p class="mb-6 whitespace-pre-line text-sm text-ink">{{ $event->description }}</p>
    @endif

    @if ($event->status->value === 'published')
        @if (session('status'))
            <p class="nexo-flash mb-4" role="status">{{ session('status') }}</p>
        @endif

        @if ($event->isSoldOut())
            <p class="nexo-flash nexo-flash--info" role="status">{{ __('This event is sold out.') }}</p>
        @else
            <form method="POST" action="{{ route('public.register', $event) }}" class="space-y-3">
                @csrf
                <input type="text" name="website" tabindex="-1" autocomplete="off" class="hidden" aria-hidden="true">
                <x-field :label="__('Your name')" name="name" required autocomplete="name" />
                <x-field :label="__('Email')" name="email" type="email" required autocomplete="email" />
                <x-button>{{ __('Register') }}</x-button>
            </form>
        @endif

        {{-- Lost-ticket recovery. Collapsed by default so it never competes with
             the registration form, which is what most visitors came for. --}}
        <details class="mt-6 border-t border-line pt-4">
            <summary class="cursor-pointer text-sm text-muted hover:text-link">
                {{ __('Already registered and can\'t find your ticket?') }}
            </summary>

            <form method="POST" action="{{ route('public.resend', $event) }}" class="mt-3 space-y-3">
                @csrf
                <input type="text" name="website" tabindex="-1" autocomplete="off" class="hidden" aria-hidden="true">
                <div>
                    {{-- Not x-field: its id is the field name, which would collide
                         with the registration form's own email input above. --}}
                    <label for="resend-email" class="mb-1 block text-sm font-medium">{{ __('Email') }}</label>
                    <input id="resend-email" type="email" name="email" required
                        class="block w-full rounded-lg border-control bg-surface text-ink focus:border-ring focus:ring-ring">
                </div>
                <p class="text-xs text-muted">
                    {{ __('We send you a new ticket and the previous one stops working.') }}
                </p>
                <x-button>{{ __('Resend my ticket') }}</x-button>
            </form>
        </details>

        {{-- Abuse report (ADR-007 §3). No account needed: requiring one to flag
             an obvious phishing event would defeat the purpose. --}}
        <details class="mt-2">
            <summary class="cursor-pointer text-xs text-muted hover:text-ink">
                {{ __('Report this event') }}
            </summary>

            <form method="POST" action="{{ route('public.report', $event) }}" class="mt-3 space-y-3">
                @csrf
                <input type="text" name="website" tabindex="-1" autocomplete="off" class="hidden" aria-hidden="true">
                <div>
                    <label for="report-reason" class="mb-1 block text-sm font-medium">{{ __('What is wrong with this event?') }}</label>
                    <textarea id="report-reason" name="reason" rows="3" required minlength="10"
                        @error('reason') aria-invalid="true" aria-describedby="report-reason-error" @enderror
                        class="block w-full rounded-lg border-control bg-surface text-ink focus:border-ring focus:ring-ring">{{ old('reason') }}</textarea>
                    @error('reason')<p id="report-reason-error" class="mt-1 text-sm text-danger-subtle-fg">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="report-email" class="mb-1 block text-sm font-medium">{{ __('Your email (optional)') }}</label>
                    <input id="report-email" type="email" name="reporter_email" value="{{ old('reporter_email') }}"
                        class="block w-full rounded-lg border-control bg-surface text-ink focus:border-ring focus:ring-ring">
                </div>
                <x-button>{{ __('Send report') }}</x-button>
            </form>
        </details>
    @elseif ($event->status->value === 'cancelled')
        <p class="nexo-flash nexo-flash--danger" role="status">{{ __('This event was cancelled.') }}</p>
    @else
        <p class="nexo-flash nexo-flash--info" role="status">{{ __('Registration for this event is closed.') }}</p>
    @endif
</x-guest-layout>
