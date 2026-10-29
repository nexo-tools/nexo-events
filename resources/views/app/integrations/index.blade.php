<x-app-layout :title="__('Integrations')">
    <h1 class="mb-1 text-xl font-bold">{{ __('Integrations') }}</h1>
    <p class="mb-4 text-sm text-muted">{{ __('Connect another site to this account so it can create and publish your events on its own.') }}</p>

    @if (session('status'))
        <p class="nexo-flash mb-4" role="status">{{ session('status') }}</p>
    @endif

    @if (session('api_token_plain'))
        {{-- Shown once, on purpose: only the hash is stored, so this value cannot
             be looked up again by anyone — including us. --}}
        <div class="nexo-flash nexo-flash--warning mb-4 block" role="status">
            <p class="font-medium">{{ __('Copy this token now. It is not shown again.') }}</p>
            <code class="mt-2 block break-all rounded-lg bg-surface-sunken p-3 font-mono text-sm text-ink">{{ session('api_token_plain') }}</code>
            <p class="mt-2 text-sm">{{ __('Paste it into the other site. We only keep a fingerprint of it, so if you lose it you will have to create a new one.') }}</p>
        </div>
    @endif

    <form method="POST" action="{{ route('integrations.store') }}" class="space-y-4 rounded-lg border border-line p-4">
        @csrf
        <x-field name="name" :label="__('What is connecting?')" required autocomplete="off" />
        <p class="-mt-3 text-xs text-muted">{{ __('A name you will recognise later, like your website.') }}</p>

        <fieldset>
            <legend class="mb-1 block text-sm font-medium">{{ __('What may it do?') }}</legend>
            <label for="ability-read" class="flex items-center gap-2 py-1 text-sm">
                <input type="checkbox" id="ability-read" name="abilities[]" value="events:read" checked
                       class="rounded border-control bg-surface text-link focus:ring-ring">
                {{ __('Read its events (name, date, how many signed up)') }}
            </label>
            <label for="ability-write" class="flex items-center gap-2 py-1 text-sm">
                <input type="checkbox" id="ability-write" name="abilities[]" value="events:write" checked
                       class="rounded border-control bg-surface text-link focus:ring-ring">
                {{ __('Create, edit, publish and cancel its events') }}
            </label>
            {{-- Unticked by default (ADR-011 §7): it is the one scope that lets
                 a third party see registrants' names. --}}
            <label for="ability-collaborators" class="flex items-start gap-2 py-1 text-sm">
                <input type="checkbox" id="ability-collaborators" name="abilities[]" value="collaborators:manage"
                       aria-describedby="ability-collaborators-warning"
                       class="mt-0.5 rounded border-control bg-surface text-link focus:ring-ring">
                <span>
                    {{ __('Invite collaborators to the events it created') }}
                    <span id="ability-collaborators-warning" class="block text-xs text-warning-subtle-fg" data-collaborators-warning>
                        {{ __('It can invite third parties to see the names of the people registered (never their emails) for the events this integration created. You get an email every time it does.') }}
                    </span>
                </span>
            </label>
            @error('abilities')
                <p class="mt-1 text-sm text-danger-subtle-fg">{{ $message }}</p>
            @enderror
        </fieldset>

        <p class="text-xs text-muted">{{ __('No integration can ever see who signed up: not their names, not their emails, not their tickets.') }}</p>

        <x-button :block="false">{{ __('Create token') }}</x-button>
    </form>

    <h2 class="mt-8 text-lg font-bold">{{ __('Connected systems') }}</h2>

    <ul class="mt-3 space-y-2">
        @forelse ($tokens as $token)
            <li class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-line p-3">
                <span>
                    <span class="font-medium">{{ $token->name }}</span>
                    @if ($token->revoked_at)
                        <span class="ms-1 text-xs font-medium text-danger-subtle-fg">{{ __('Revoked') }}</span>
                    @endif
                    <span class="block text-xs text-muted">
                        {{ implode(' · ', $token->abilities) }}
                        ·
                        @if ($token->last_used_at)
                            {{ __('last used :date', ['date' => $token->last_used_at->translatedFormat(__('app.datetime'))]) }}
                        @else
                            {{ __('never used') }}
                        @endif
                        · {{ __('created :date', ['date' => $token->created_at->translatedFormat(__('app.date'))]) }}
                    </span>
                </span>
                @unless ($token->revoked_at)
                    <form method="POST" action="{{ route('integrations.revoke', $token) }}"
                          onsubmit="return confirm(@js(__('Revoke “:name”? It stops working immediately and cannot be restored.', ['name' => $token->name])))">
                        @csrf
                        <x-button variant="danger" class="nexo-btn--sm" :block="false">{{ __('Revoke') }}</x-button>
                    </form>
                @endunless
            </li>
        @empty
            <li class="rounded-lg border border-dashed border-line p-6 text-center">
                <p class="text-sm font-medium">{{ __('No system is connected yet.') }}</p>
                <p class="mt-1 text-sm text-muted">{{ __('Create a token above and paste it into the site you want to connect.') }}</p>
            </li>
        @endforelse
    </ul>

    <a href="{{ route('dashboard') }}" class="mt-6 block text-sm text-muted hover:underline">{{ __('Back to the dashboard') }}</a>
</x-app-layout>
