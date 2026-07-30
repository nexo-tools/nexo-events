{{-- One button shape for every verb in the app. The .nexo-btn layer (nexo-ui.css)
     carries the 44px touch target, the focus ring and the disabled/busy
     treatment, so a variant only has to pick a role. --}}
@props(['type' => 'submit', 'variant' => 'primary', 'block' => true])

<button type="{{ $type }}" {{ $attributes->class([
    'nexo-btn',
    'nexo-btn--primary' => $variant === 'primary',
    'nexo-btn--ghost' => $variant === 'secondary',
    'nexo-btn--danger' => $variant === 'danger',
    'w-full' => $block,
]) }}>
    {{ $slot }}
</button>
