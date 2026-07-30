<x-app-layout>
    <h1 class="mb-1 text-xl font-bold">{{ $event->title }}</h1>
    <p class="mb-4 text-sm text-muted">{{ __('Status') }}: {{ $event->status->label() }}</p>

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
        <div class="flex flex-wrap gap-2">
            @if (in_array($event->status->value, ['draft', 'closed']))
                <form method="POST" action="{{ route('events.publish', $event) }}">@csrf<x-button :block="false">{{ __('Publish') }}</x-button></form>
            @endif
            @if ($event->status->value === 'published')
                <form method="POST" action="{{ route('events.close', $event) }}">@csrf<x-button variant="secondary" :block="false">{{ __('Close registration') }}</x-button></form>
            @endif
            <a href="{{ route('events.registrations', $event) }}" class="nexo-btn nexo-btn--ghost">{{ __('Registrations') }}</a>
            <a href="{{ route('events.scan', $event) }}" class="nexo-btn nexo-btn--ghost">{{ __('Check-in') }}</a>
        </div>

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

    <a href="{{ route('dashboard') }}" class="mt-4 block text-sm text-muted hover:underline">{{ __('Back to the dashboard') }}</a>
</x-app-layout>
