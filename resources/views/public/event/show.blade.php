<x-guest-layout>
    <h1 class="mb-1 text-2xl font-bold">{{ $event->title }}</h1>
    <p class="mb-4 text-sm text-slate-500">
        {{ $event->starts_at->format('d/m/Y H:i') }}@if ($event->venue) · {{ $event->venue }}@endif
    </p>

    @if ($event->description)
        <p class="mb-6 whitespace-pre-line text-sm text-slate-700 dark:text-slate-300">{{ $event->description }}</p>
    @endif

    @if ($event->status->value === 'published')
        @if (session('status'))
            <p class="mb-4 rounded-lg bg-brand-100 px-4 py-3 text-sm text-brand-900" role="status">{{ session('status') }}</p>
        @endif

        @if ($event->isSoldOut())
            <p class="rounded-lg bg-slate-100 px-4 py-3 text-sm dark:bg-slate-800">{{ __('El evento está agotado.') }}</p>
        @else
            <form method="POST" action="{{ route('public.register', $event) }}" class="space-y-3">
                @csrf
                <input type="text" name="website" tabindex="-1" autocomplete="off" class="hidden" aria-hidden="true">
                <div>
                    <label class="block text-sm font-medium">{{ __('Tu nombre') }}</label>
                    <input name="name" value="{{ old('name') }}" required class="mt-1 block w-full rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-800">
                    @error('name')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium">{{ __('Email') }}</label>
                    <input type="email" name="email" value="{{ old('email') }}" required class="mt-1 block w-full rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-800">
                    @error('email')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <x-button>{{ __('Registrarme') }}</x-button>
            </form>
        @endif
    @elseif ($event->status->value === 'cancelled')
        <p class="rounded-lg bg-red-100 px-4 py-3 text-sm text-red-900">{{ __('Este evento fue cancelado.') }}</p>
    @else
        <p class="rounded-lg bg-slate-100 px-4 py-3 text-sm dark:bg-slate-800">{{ __('El registro para este evento está cerrado.') }}</p>
    @endif
</x-guest-layout>
