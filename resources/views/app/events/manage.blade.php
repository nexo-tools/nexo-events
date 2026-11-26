{{-- The event's summary (SPEC-event-panel): where every event opens, for the
     owner and for a collaborator (ADR-011), designed for a phone at the door.
     Content is read-only here — it belongs to the owner, under Edit. --}}
<x-app-layout :title="__('Ticket management')">
    @include('app.events._header', ['event' => $event, 'current' => 'summary'])

    @if (session('status'))
        <p class="nexo-flash mb-4" role="status">{{ session('status') }}</p>
    @endif
    @error('reopen')
        <p class="nexo-flash nexo-flash--warning mb-4" role="alert">{{ $message }}</p>
    @enderror

    @if ($isOwner && $event->status->value === 'draft')
        <p class="nexo-flash nexo-flash--info mb-4" role="note" data-draft-notice>
            <span>
                {{ __('This event is a draft: nobody can see it yet.') }}
                <a href="{{ route('events.edit', $event) }}" class="font-medium underline">{{ __('Complete it and publish it') }}</a>
            </span>
        </p>
    @endif

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

    @if ($event->access_address || $event->access_info || $event->payment_info)
        <dl class="mb-6 space-y-3 rounded-lg border border-line p-3 text-sm">
            @if ($event->payment_info)
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted">{{ __('Entry') }}</dt>
                    <dd>{{ $event->payment_info }}</dd>
                </div>
            @endif
            @if ($event->access_address)
                <div data-access-address>
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted">{{ __('Address') }}</dt>
                    <dd class="break-words">{{ $event->access_address }}</dd>
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

    {{-- A draft has no public page yet (it answers 404): no link to hand out. --}}
    @if ($event->status->isPublic())
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
    @endif

    {{-- Collaborators sit next to what they manage (capacity, registrations,
         the door), owner only (ADR-011). --}}
    @if ($isOwner)
        @include('app.events._collaborators', ['event' => $event, 'collaborators' => $collaborators])
    @endif
</x-app-layout>
