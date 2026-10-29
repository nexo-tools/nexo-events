{{-- Ticket management (ADR-011): where a collaborator lands, designed for a
     phone at the door. Content is read-only here — it belongs to the owner. --}}
<x-app-layout :title="__('Ticket management')">
    <h1 class="mb-1 text-xl font-bold">{{ $event->title }}</h1>
    <p class="mb-4 text-sm text-muted">
        {{ $event->starts_at->translatedFormat(__('app.datetime')) }}@if ($event->venue) · {{ $event->venue }}@endif
        · {{ $event->status->label() }}
    </p>

    @if (session('status'))
        <p class="nexo-flash mb-4" role="status">{{ session('status') }}</p>
    @endif
    @error('reopen')
        <p class="nexo-flash nexo-flash--warning mb-4" role="alert">{{ $message }}</p>
    @enderror

    <div class="mb-6 grid grid-cols-2 gap-3" data-counters>
        <div class="rounded-lg border border-line p-3 text-center">
            <p class="text-3xl font-bold" data-registered>{{ $issued }}@if ($event->capacity !== null)<span class="text-lg font-medium text-muted"> / {{ $event->capacity }}</span>@endif</p>
            <p class="text-xs text-muted">{{ __('Registered') }}</p>
        </div>
        <div class="rounded-lg border border-line p-3 text-center">
            <p class="text-3xl font-bold" data-checked-in>{{ $checkedIn }}</p>
            <p class="text-xs text-muted">{{ __('Came in') }}</p>
        </div>
    </div>

    <div class="mb-6 grid grid-cols-1 gap-2 sm:grid-cols-2">
        <a href="{{ route('events.scan', $event) }}" class="nexo-btn nexo-btn--primary w-full">{{ __('Door check-in') }}</a>
        <a href="{{ route('events.registrations', $event) }}" class="nexo-btn nexo-btn--ghost w-full">{{ __('Registrations') }}</a>
    </div>

    @if ($event->access_info || $event->payment_info)
        <dl class="mb-6 space-y-3 rounded-lg border border-line p-3 text-sm">
            @if ($event->payment_info)
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted">{{ __('Entry') }}</dt>
                    <dd>{{ $event->payment_info }}</dd>
                </div>
            @endif
            @if ($event->access_info)
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted">{{ __('Only for ticket holders') }}</dt>
                    <dd class="whitespace-pre-line">{{ $event->access_info }}</dd>
                </div>
            @endif
        </dl>
    @endif

    <form method="POST" action="{{ route('events.capacity', $event) }}" class="mb-6 space-y-3">
        @csrf
        <x-field :label="__('Capacity')" name="capacity" type="number" min="1" :value="$event->capacity"
                 :hint="__('Empty means no limit. It cannot be lower than the tickets already issued.')" />
        <x-button variant="secondary" :block="false">{{ __('Save capacity') }}</x-button>
    </form>

    <div class="mb-6">
        @if ($event->status->value === 'published')
            <form method="POST" action="{{ route('events.close', $event) }}">@csrf
                <x-button variant="secondary" :block="false">{{ __('Close registration') }}</x-button>
            </form>
        @elseif ($event->status->value === 'closed')
            <form method="POST" action="{{ route('events.reopen', $event) }}">@csrf
                <x-button :block="false">{{ __('Reopen registration') }}</x-button>
            </form>
        @endif
    </div>

    <div class="border-t border-line pt-4">
        <label for="public-url" class="mb-1 block text-sm font-medium">{{ __('Public page') }}</label>
        <div class="flex gap-2">
            <input id="public-url" type="url" readonly value="{{ route('public.event', $event) }}"
                   class="block w-full min-w-0 rounded-lg border-control bg-surface text-sm text-ink focus:border-ring focus:ring-ring">
            {{-- Revealed by app.js only where the Clipboard API exists; without
                 it the field above is selectable by hand. --}}
            <button type="button" hidden data-copy="public-url" data-copied="{{ __('Copied') }}" class="nexo-btn nexo-btn--ghost">{{ __('Copy') }}</button>
        </div>
    </div>

    @if ($isOwner)
        <a href="{{ route('events.edit', $event) }}" class="mt-6 block text-sm text-muted hover:underline">{{ __('Edit event') }}</a>
    @endif
    <a href="{{ route('dashboard') }}" class="mt-4 block text-sm text-muted hover:underline">{{ __('Back to the dashboard') }}</a>
</x-app-layout>
