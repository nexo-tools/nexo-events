<x-public-layout :title="$title" :description="$description" :seo-type="$seoType" :seo-json-ld="$seoJsonLd" :edit-url="$editUrl">
    <h1 class="mb-1 text-2xl font-bold">{{ $event->title }}</h1>
    <p class="mb-4 text-sm text-muted">
        {{ $event->starts_at->translatedFormat(__('app.datetime')) }}@if ($event->venue) · {{ $event->venue }}@endif
    </p>

    @if ($event->description)
        <p class="mb-6 whitespace-pre-line text-sm text-ink">{{ $event->description }}</p>
    @endif

    {{-- PLAN 10.15: the organizer's "More information" link. Anyone can create
         events here, so the button prints the domain it leads to instead of
         letting the label vouch for it; nofollow ugc because the instance does
         not endorse it. Only on this page: never on the ticket, the emails, the
         JSON-LD or the sitemap. infoUrlHost() is null for anything that is not
         an absolute https URL, and then nothing renders. --}}
    @if ($event->infoUrlHost())
        <p class="mb-6" data-info-url>
            <a href="{{ $event->info_url }}" target="_blank" rel="noopener noreferrer nofollow ugc"
               class="nexo-btn nexo-btn--ghost max-w-full flex-wrap">
                <span>{{ __('More information') }}</span>
                <span class="break-all text-xs font-normal text-muted" data-info-url-host>{{ $event->infoUrlHost() }} <span aria-hidden="true">↗</span></span>
                <span class="sr-only">{{ __('(opens in a new tab)') }}</span>
            </a>
        </p>
    @endif

    {{-- Price text, before anyone decides to register (ADR-012). Nothing is
         collected here: registering is a reservation. access_info is never
         rendered on this page — it is for ticket holders only. --}}
    @if ($event->payment_info)
        <p class="mb-6 rounded-lg border border-line p-3 text-sm text-ink" data-payment-info>
            <span class="block text-xs font-medium uppercase tracking-wide text-muted">{{ __('Entry') }}</span>
            {{ $event->payment_info }}
        </p>
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
                {{-- PLAN 10.13: only where the instance collects it
                     (NEXO_ATTENDEE_PHONE=optional), never required, and only
                     the event owner ever sees it. 10.16: with its country. --}}
                @if ($askPhone)
                    <x-phone-field :label="__('WhatsApp or phone (optional)')"
                                   :hint="__('Only the organizer sees it, in case they need to reach you.')" />
                @endif
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
</x-public-layout>
