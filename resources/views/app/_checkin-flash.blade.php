{{-- The door result, rendered from the raw outcome code the controller flashes.
     Shared by the scanner and the manual check-in so neither prints the code
     itself ("Check-in: already") at the door. Expects $result and, optionally,
     session('ticketName'). --}}
<div @class(['nexo-flash mb-4', 'nexo-flash--danger' => $result !== 'ok'])
     role="{{ $result === 'ok' ? 'status' : 'alert' }}">
    <span>
        @switch($result)
            @case('ok') ✓ {{ __('Ingreso válido') }} @if(session('ticketName')) — {{ session('ticketName') }} @endif @break
            @case('already') ✗ {{ __('Entrada ya usada') }} @break
            @case('revoked') ✗ {{ __('Entrada revocada') }} @break
            @case('event_inactive') ✗ {{ __('Evento cancelado') }} @break
            @default ✗ {{ __('Entrada no válida') }}
        @endswitch
    </span>
</div>
