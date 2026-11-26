{{-- Edit: the owner's screen for the content and the event's life (publish,
     cancel). Registrations, the door, capacity and collaborators live under
     the other tabs of the event navigation (SPEC-event-panel). --}}
<x-app-layout>
    @include('app.events._header', ['event' => $event, 'current' => 'edit'])

    {{-- Content is last-write-wins from whatever system created the event
         (ADR-011 / SPEC-integration-api): say so before somebody edits here. --}}
    @if ($event->external_ref !== null)
        <p class="nexo-flash nexo-flash--info mb-4" role="note" data-external-notice>
            {{ __('This event is managed by another system: content changes made here may be overwritten from there.') }}
        </p>
    @endif

    @if (session('status'))
        <p class="nexo-flash mb-4" role="status">{{ session('status') }}</p>
    @endif

    @error('publish')
        <p class="nexo-flash nexo-flash--warning mb-4" role="alert">
            <span>
                {{ $message }}
                <a href="{{ route('verification.notice') }}" class="font-medium underline">{{ __('Resend the link') }}</a>
            </span>
        </p>
    @enderror

    <form method="POST" action="{{ route('events.update', $event) }}" class="space-y-4">
        @csrf @method('PUT')
        @include('app.events._fields', ['event' => $event])
        <x-button>{{ __('Save changes') }}</x-button>
    </form>

    <div class="mt-6 space-y-2 border-t border-line pt-4">
        {{-- A draft's page answers 404 until it is published: say where it
             will be, without a link that leads nowhere. --}}
        <p class="text-sm">
            @if ($event->status->value === 'draft')
                {{ __('Public page, once published') }}:
                <span class="break-all text-muted">{{ route('public.event', $event) }}</span>
            @else
                {{ __('Public page') }}:
                <a href="{{ route('public.event', $event) }}" class="break-all text-link hover:underline">{{ route('public.event', $event) }}</a>
            @endif
        </p>
        {{-- Closing and reopening registration are on the summary, where a
             collaborator can reach them too; publishing a draft is the owner's
             and happens here, after the content is in. --}}
        @if ($event->status->value === 'draft')
            <div class="flex flex-wrap gap-2">
                <form method="POST" action="{{ route('events.publish', $event) }}">@csrf<x-button :block="false">{{ __('Publish') }}</x-button></form>
            </div>
        @endif

        @error('delete')
            <p class="nexo-flash nexo-flash--danger" role="alert">{{ $message }}</p>
        @enderror

        {{-- The one destructive action that fits the state (AC-PANEL-5): a
             draft was never public, so it is deleted; a published or closed
             event is cancelled, which tells the people holding a ticket; a
             cancelled or blocked one offers neither. Cancelling a draft used to
             be offered here — and a cancelled event shows its public page.
             Each sits on its own row, in the danger variant, behind a prompt
             that names the event (data-confirm, run by app.js: the CSP blocks
             inline handlers, so the old inline confirm() never asked). --}}
        @if ($event->status->value === 'draft')
            <form method="POST" action="{{ route('events.destroy', $event) }}" class="pt-2" data-delete-draft
                  data-confirm="{{ $event->external_ref !== null
                      ? __('Delete the draft “:title”? It cannot be undone, and the system that created it will not find it any more.', ['title' => $event->title])
                      : __('Delete the draft “:title”? It cannot be undone.', ['title' => $event->title]) }}">
                @csrf @method('DELETE')
                <x-button variant="danger" :block="false">{{ __('Delete draft') }}</x-button>
            </form>
        @elseif (in_array($event->status->value, ['published', 'closed']))
            <form method="POST" action="{{ route('events.cancel', $event) }}" class="pt-2" data-cancel-event
                  data-confirm="{{ __('Cancel “:title”? Registration closes and anyone already holding a ticket will not get in.', ['title' => $event->title]) }}">
                @csrf
                <x-button variant="danger" :block="false">{{ __('Cancel event') }}</x-button>
            </form>
        @endif
    </div>
</x-app-layout>
