<x-guest-layout :noindex="true">
    @unless (auth()->user()->hasVerifiedEmail())
        <div class="nexo-flash nexo-flash--warning mb-4" role="status">
            <span>
                {{ __('Verificá tu email para poder publicar eventos.') }}
                <a href="{{ route('verification.notice') }}" class="font-medium underline">{{ __('Reenviar el enlace') }}</a>
            </span>
        </div>
    @endunless

    @if (session('status'))
        <p class="nexo-flash mb-4" role="status">{{ session('status') }}</p>
    @endif

    <div class="flex items-center justify-between gap-3">
        <h1 class="text-xl font-bold">{{ __('Tus eventos') }}</h1>
        <a href="{{ route('events.create') }}" class="nexo-btn nexo-btn--primary nexo-btn--sm">{{ __('Nuevo evento') }}</a>
    </div>

    <ul class="mt-4 space-y-2">
        @forelse ($events as $event)
            <li class="rounded-lg border border-line p-3">
                <a href="{{ route('events.edit', $event) }}" class="font-medium hover:underline">{{ $event->title }}</a>
                <div class="text-xs text-muted">{{ $event->starts_at->format('d/m/Y H:i') }} · {{ $event->status->label() }} · {{ __(':count entradas', ['count' => $event->tickets_count]) }} · {{ __(':count visitas', ['count' => $event->views_count]) }}</div>
            </li>
        @empty
            <li class="text-sm text-muted">{{ __('Aún no creaste ningún evento.') }}</li>
        @endforelse
    </ul>

    <form method="POST" action="{{ config('nexo-sso.enabled') ? route('nexo-sso.logout') : route('logout') }}" class="mt-6">
        @csrf
        <button type="submit" class="text-sm text-muted hover:underline">{{ __('Cerrar sesión') }}</button>
    </form>
</x-guest-layout>
