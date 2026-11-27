{{-- One row of the registrations list (shared by «Invitados» and «General»).
     Expects $ticket, $showEmails, $eventActive and $canRestore. --}}
<li class="flex items-center justify-between gap-3 rounded-lg border border-line p-3">
    <div class="min-w-0">
        <span class="font-medium">{{ $ticket->attendee_name }}</span>
        {{-- Same rule as the phone line below (10.16, 10.22): pieces
             that wrap whole with a gap, no printed separator — a long
             email used to push "· Valid" alone onto the next line, and
             an email wider than the column broke the page width. --}}
        <span class="flex flex-wrap gap-x-2 text-xs text-muted" data-ticket-meta>
            @if ($showEmails)
                <span class="min-w-0 wrap-anywhere" data-ticket-email>{{ $ticket->attendee_email }}</span>
            @endif
            <span class="whitespace-nowrap" data-ticket-status>{{ $ticket->status->label() }}</span>
            @if ($ticket->is_guest && $ticket->status->value !== 'revoked')
                <span class="whitespace-nowrap font-medium text-fg" data-ticket-guest>{{ __('Guest') }}</span>
            @endif
        </span>
        {{-- PLAN 10.13: the optional phone sits with the email, so
             it rides the same owner-only condition. Collaborators
             never get it. 10.16: formatted the way its country
             writes it, the number dials and WhatsApp opens the chat. --}}
        @if ($showEmails && $ticket->attendee_phone)
            {{-- Pieces that wrap whole (never mid-number) and no
                 separator left dangling at a line end — the
                 column is narrow next to the door buttons. --}}
            @php($phone = \App\Support\AttendeePhone::present($ticket->attendee_phone))
            <span class="flex flex-wrap gap-x-2 text-xs text-muted" data-attendee-phone>
                <a href="{{ $phone['tel'] }}" class="whitespace-nowrap text-link underline">{{ $phone['display'] }}</a>
                @if ($phone['country'])
                    <span class="whitespace-nowrap">({{ $phone['country'] }})</span>
                @endif
                @if ($phone['whatsapp'])
                    <a href="{{ $phone['whatsapp'] }}" target="_blank" rel="noopener noreferrer" class="whitespace-nowrap text-link underline">WhatsApp</a>
                @endif
            </span>
        @endif
        {{-- «Invitado» (AC-PANEL-11): takes no room of the capacity; the door scans it like any other. --}}
        @if ($ticket->status->value !== 'revoked')
            <form method="POST" action="{{ route('tickets.guest', $ticket) }}">@csrf
                <input type="hidden" name="guest" value="{{ $ticket->is_guest ? '0' : '1' }}">
                <button type="submit" class="inline-flex min-h-11 items-center text-xs font-medium text-link hover:underline" data-guest-toggle>{{ $ticket->is_guest ? __('Remove from guests') : __('Mark as guest') }}</button>
            </form>
        @endif
    </div>
    @if ($ticket->checkin === null && $ticket->status->value === 'valid')
        <div class="flex flex-wrap gap-2">
            <form method="POST" action="{{ route('tickets.checkin', $ticket) }}">@csrf
                <x-button variant="secondary" class="nexo-btn--sm" :block="false">{{ __('Check in') }}</x-button>
            </form>
            {{-- ADR-011 §6: the reservation that was never paid. --}}
            <form method="POST" action="{{ route('tickets.release', $ticket) }}"
                  data-confirm="{{ __('Release the spot of :name? Their ticket stops working at the door.', ['name' => $ticket->attendee_name]) }}">@csrf
                <x-button variant="secondary" class="nexo-btn--sm" :block="false">{{ __('Release spot') }}</x-button>
            </form>
        </div>
    @elseif ($ticket->status->value === 'revoked')
        {{-- «Devolver el lugar» (AC-COLLAB-19): its status already says «Lugar liberado». --}}
        @if (! $eventActive)
            {{-- A cancelled event gives nothing back: no reason to print. --}}
        @elseif ($ticket->is_guest || $canRestore)
            <form method="POST" action="{{ route('tickets.restore', $ticket) }}"
                  data-confirm="{{ __('Give :name their spot back? Their ticket gets in at the door again.', ['name' => $ticket->attendee_name]) }}">@csrf
                <x-button variant="secondary" class="nexo-btn--sm" :block="false">{{ __('Give spot back') }}</x-button>
            </form>
        @else
            <span class="text-xs font-medium text-muted" data-no-room>{{ __('No room to give it back') }}</span>
        @endif
    @else
        <span class="text-xs font-medium text-success-subtle-fg">{{ __('Checked in') }}</span>
    @endif
</li>
