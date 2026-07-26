<x-guest-layout :noindex="true">
    <h1 class="mb-1 text-xl font-bold">{{ $event->title }}</h1>
    <p class="mb-4 text-sm text-slate-500">{{ __('Estado') }}: {{ $event->status->value }}</p>

    @if (session('status'))
        <p class="mb-4 rounded-lg bg-brand-100 px-4 py-3 text-sm text-brand-900" role="status">{{ session('status') }}</p>
    @endif

    @error('publish')
        <p class="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100" role="alert">
            {{ $message }}
            <a href="{{ route('verification.notice') }}" class="font-medium underline">{{ __('Reenviar el enlace') }}</a>
        </p>
    @enderror

    <form method="POST" action="{{ route('events.update', $event) }}" class="space-y-4">
        @csrf @method('PUT')
        @include('app.events._fields', ['event' => $event])
        <x-button>{{ __('Guardar cambios') }}</x-button>
    </form>

    <div class="mt-6 space-y-2 border-t border-slate-200 pt-4 dark:border-slate-700">
        <p class="text-sm">
            {{ __('Página pública') }}:
            <a href="{{ route('public.event', $event) }}" class="text-brand-700 hover:underline dark:text-brand-400">{{ route('public.event', $event) }}</a>
        </p>
        <div class="flex flex-wrap gap-2">
            @if (in_array($event->status->value, ['draft', 'closed']))
                <form method="POST" action="{{ route('events.publish', $event) }}">@csrf<x-button>{{ __('Publicar') }}</x-button></form>
            @endif
            @if ($event->status->value === 'published')
                <form method="POST" action="{{ route('events.close', $event) }}">@csrf<x-button>{{ __('Cerrar registro') }}</x-button></form>
            @endif
            <form method="POST" action="{{ route('events.cancel', $event) }}" onsubmit="return confirm('{{ __('¿Cancelar el evento?') }}')">@csrf<x-button>{{ __('Cancelar evento') }}</x-button></form>
            <a href="{{ route('events.registrations', $event) }}" class="inline-flex items-center rounded-lg border border-slate-300 px-4 py-2 text-sm dark:border-slate-600">{{ __('Registrados') }}</a>
            <a href="{{ route('events.scan', $event) }}" class="inline-flex items-center rounded-lg border border-slate-300 px-4 py-2 text-sm dark:border-slate-600">{{ __('Check-in') }}</a>
        </div>
    </div>
</x-guest-layout>
