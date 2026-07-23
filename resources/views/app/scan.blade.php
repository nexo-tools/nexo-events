<x-guest-layout>
    <h1 class="mb-1 text-xl font-bold">{{ __('Check-in en puerta') }}</h1>
    <p class="mb-4 text-sm text-slate-500">{{ $event->title }}</p>

    @if (session('checkin'))
        @php($r = session('checkin'))
        <div @class([
            'mb-4 rounded-lg px-4 py-3 text-sm font-medium',
            'bg-green-100 text-green-900' => $r === 'ok',
            'bg-red-100 text-red-900' => $r !== 'ok',
        ]) role="status">
            @switch($r)
                @case('ok') ✓ {{ __('Ingreso válido') }} @if(session('ticketName')) — {{ session('ticketName') }} @endif @break
                @case('already') ✗ {{ __('Entrada ya usada') }} @break
                @case('revoked') ✗ {{ __('Entrada revocada') }} @break
                @case('event_inactive') ✗ {{ __('Evento cancelado') }} @break
                @default ✗ {{ __('Entrada no válida') }}
            @endswitch
        </div>
    @endif

    {{-- MVP: manual token entry. Camera QR-decode is a Phase 3 spike (documented). --}}
    <form method="POST" action="{{ route('events.checkin', $event) }}" class="space-y-3">
        @csrf
        <label class="block text-sm font-medium">{{ __('Código de la entrada') }}</label>
        <input name="token" autofocus autocomplete="off" class="block w-full rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-800">
        <x-button>{{ __('Validar') }}</x-button>
    </form>

    <a href="{{ route('events.registrations', $event) }}" class="mt-4 block text-sm text-slate-500 hover:underline">{{ __('Ver registrados') }}</a>
</x-guest-layout>
