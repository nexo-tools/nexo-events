<x-app-layout>
    @unless (auth()->user()->hasVerifiedEmail())
        <div class="nexo-flash nexo-flash--warning mb-4" role="status">
            <span>
                {{ __('Verify your email to publish events.') }}
                <a href="{{ route('verification.notice') }}" class="font-medium underline">{{ __('Resend the link') }}</a>
            </span>
        </div>
    @endunless

    @if (session('status'))
        <p class="nexo-flash mb-4" role="status">{{ session('status') }}</p>
    @endif

    <div class="flex items-center justify-between gap-3">
        <h1 class="text-xl font-bold">{{ __('Your events') }}</h1>
        <a href="{{ route('events.create') }}" class="nexo-btn nexo-btn--primary nexo-btn--sm">{{ __('New event') }}</a>
    </div>

    <ul class="mt-4 space-y-2">
        @forelse ($events as $event)
            <li class="rounded-lg border border-line p-3">
                {{-- Every event opens on its summary, the owner's as much as a
                     collaborator's (SPEC-event-panel AC-PANEL-1). --}}
                <a href="{{ route('events.manage', $event) }}" class="font-medium hover:underline">{{ $event->title }}</a>
                <div class="text-xs text-muted">{{ $event->starts_at->translatedFormat(__('app.datetime')) }} · {{ $event->status->label() }} · {{ trans_choice('app.tickets', $event->tickets_count) }} · {{ trans_choice('app.views', $event->views_count) }}</div>
            </li>
        @empty
            <li class="rounded-lg border border-dashed border-line p-6 text-center">
                <p class="text-sm font-medium">{{ __('You haven\'t created any events yet.') }}</p>
                <p class="mt-1 text-sm text-muted">{{ __('You create the event, share its public page and validate tickets with a QR code at the door.') }}</p>
            </li>
        @endforelse
    </ul>

    {{-- Events somebody else owns and invited this account to manage
         (ADR-011). Absent when there are none, so an organizer who never
         collaborates sees the dashboard exactly as before. --}}
    @if ($managed->isNotEmpty())
        <h2 class="mt-8 text-lg font-bold">{{ __('Events you manage') }}</h2>
        <ul class="mt-3 space-y-2" data-managed-events>
            @foreach ($managed as $event)
                <li class="rounded-lg border border-line p-3">
                    <a href="{{ route('events.manage', $event) }}" class="font-medium hover:underline">{{ $event->title }}</a>
                    <div class="text-xs text-muted">{{ $event->starts_at->translatedFormat(__('app.datetime')) }} · {{ $event->status->label() }} · {{ trans_choice('app.tickets', $event->tickets_count) }}</div>
                </li>
            @endforeach
        </ul>
    @endif

    {{-- Only where the API is on: an instance that never turns it on has no
         reason to show a door to it (AC-API-15b). --}}
    @if (config('nexo.api.enabled'))
        <p class="mt-6 text-sm">
            <a href="{{ route('integrations.index') }}" class="text-muted hover:underline">{{ __('Connect another site to this account') }}</a>
        </p>
    @endif
</x-app-layout>
