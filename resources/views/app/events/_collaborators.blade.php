{{-- Collaborators (ADR-011), owner only — rendered on the event's summary
     behind an owner check (SPEC-event-panel AC-PANEL-3), next to what they
     manage. Expects $event and $collaborators. --}}
<section class="mt-8 border-t border-line pt-6" aria-labelledby="collaborators-heading">
    <h2 id="collaborators-heading" class="text-lg font-bold">{{ __('Collaborators') }}</h2>
    <p class="mb-4 text-sm text-muted">{{ __('Invite someone to manage this event\'s tickets with their own account: capacity, registrations (names, not emails) and the door. They cannot edit or cancel the event.') }}</p>

    @if (session('collaborator_link'))
        {{-- Shown once (ADR-011 §3): only its hash is stored. Sharing it is safe
             because only an account with the invited, verified email can accept. --}}
        <div class="nexo-flash nexo-flash--info mb-4 block" role="status" data-invite-link>
            <p class="font-medium">{{ __('The invitation is on its way. You can also share this link — it only works for the invited email, and it is not shown again.') }}</p>
            <code class="mt-2 block break-all rounded-lg bg-surface-sunken p-3 font-mono text-sm text-ink">{{ session('collaborator_link') }}</code>
            <a class="nexo-btn nexo-btn--ghost mt-2"
               href="https://wa.me/?text={{ rawurlencode(__('You are invited to manage the tickets of :event: :url', ['event' => session('collaborator_link_event'), 'url' => session('collaborator_link')])) }}"
               target="_blank" rel="noopener noreferrer">{{ __('Share on WhatsApp') }}</a>
        </div>
    @endif

    <form method="POST" action="{{ route('collaborators.store', $event) }}" class="mb-4 space-y-3">
        @csrf
        <x-field :label="__('Email of the person to invite')" name="collaborator_email" type="email" required autocomplete="off" />
        <x-button :block="false">{{ __('Invite') }}</x-button>
    </form>

    <ul class="space-y-2">
        @forelse ($collaborators as $collaborator)
            @php($state = $collaborator->status())
            <li class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-line p-3" data-collaborator="{{ $collaborator->email }}">
                <span>
                    <span class="block break-all font-medium">{{ $collaborator->email }}</span>
                    <span class="block text-xs text-muted">
                        @switch($state)
                            @case('accepted') {{ __('Accepted') }} @break
                            @case('expired') {{ __('Invitation expired') }} @break
                            @case('revoked') {{ __('Access removed') }} @break
                            @default {{ __('Invited, not accepted yet') }}
                        @endswitch
                        @if ($collaborator->invited_via) · {{ __('via :name', ['name' => $collaborator->invited_via]) }} @endif
                    </span>
                </span>
                <span class="flex flex-wrap gap-2">
                    @if ($state !== 'accepted')
                        <form method="POST" action="{{ route('collaborators.store', $event) }}">@csrf
                            <input type="hidden" name="collaborator_email" value="{{ $collaborator->email }}">
                            <x-button variant="secondary" class="nexo-btn--sm" :block="false">{{ $state === 'revoked' ? __('Invite again') : __('Resend') }}</x-button>
                        </form>
                    @endif
                    @if ($state !== 'revoked')
                        <form method="POST" action="{{ route('collaborators.revoke', [$event, $collaborator]) }}"
                              data-confirm="{{ __('Remove access for :email? It stops working immediately.', ['email' => $collaborator->email]) }}">@csrf
                            <x-button variant="danger" class="nexo-btn--sm" :block="false">{{ __('Remove access') }}</x-button>
                        </form>
                    @endif
                </span>
            </li>
        @empty
            <li class="rounded-lg border border-dashed border-line p-4 text-center text-sm text-muted">{{ __('Nobody else manages this event yet.') }}</li>
        @endforelse
    </ul>
</section>
