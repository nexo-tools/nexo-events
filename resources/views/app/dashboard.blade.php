<x-guest-layout :noindex="true">
    @unless (auth()->user()->hasVerifiedEmail())
        <div class="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100" role="status">
            {{ __('Verificá tu email para poder publicar eventos.') }}
            <a href="{{ route('verification.notice') }}" class="font-medium underline">{{ __('Reenviar el enlace') }}</a>
        </div>
    @endunless

    @if (session('status'))
        <p class="mb-4 rounded-lg bg-brand-100 px-4 py-3 text-sm text-brand-900" role="status">{{ session('status') }}</p>
    @endif

    <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold">{{ __('Tus eventos') }}</h1>
        <a href="{{ route('events.create') }}" class="rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-700">{{ __('Nuevo evento') }}</a>
    </div>

    <ul class="mt-4 space-y-2">
        @forelse ($events as $event)
            <li class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                <a href="{{ route('events.edit', $event) }}" class="font-medium hover:underline">{{ $event->title }}</a>
                <div class="text-xs text-slate-500">{{ $event->starts_at->format('d/m/Y H:i') }} · {{ $event->status->value }} · {{ __(':count entradas', ['count' => $event->tickets_count]) }} · {{ __(':count visitas', ['count' => $event->views_count]) }}</div>
            </li>
        @empty
            <li class="text-sm text-slate-600 dark:text-slate-400">{{ __('Aún no creaste ningún evento.') }}</li>
        @endforelse
    </ul>

    <form method="POST" action="{{ route('logout') }}" class="mt-6">
        @csrf
        <button type="submit" class="text-sm text-slate-500 hover:underline">{{ __('Cerrar sesión') }}</button>
    </form>
</x-guest-layout>
