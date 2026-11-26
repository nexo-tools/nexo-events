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
        <p class="text-sm">
            {{ __('Public page') }}:
            <a href="{{ route('public.event', $event) }}" class="break-all text-link hover:underline">{{ route('public.event', $event) }}</a>
        </p>
        {{-- Closing and reopening registration are on the summary, where a
             collaborator can reach them too; publishing a draft is the owner's
             and happens here, after the content is in. --}}
        @if ($event->status->value === 'draft')
            <div class="flex flex-wrap gap-2">
                <form method="POST" action="{{ route('events.publish', $event) }}">@csrf<x-button :block="false">{{ __('Publish') }}</x-button></form>
            </div>
        @endif

        {{-- Cancelling is irreversible from the UI: Publicar only renders for a
             draft or closed event, so a cancelled one can never be republished.
             Hence its own row, the danger variant, and a prompt that names the
             event instead of asking "¿Cancelar el evento?" next to four
             identical violet buttons. --}}
        <form method="POST" action="{{ route('events.cancel', $event) }}" class="pt-2"
              onsubmit="return confirm(@js(__('Cancel “:title”? Registration closes and anyone already holding a ticket will not get in.', ['title' => $event->title])))">
            @csrf
            <x-button variant="danger" :block="false">{{ __('Cancel event') }}</x-button>
        </form>
    </div>

</x-app-layout>
