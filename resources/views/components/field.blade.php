@props(['label', 'name', 'type' => 'text', 'value' => null, 'required' => false, 'autocomplete' => null])

<div>
    <label for="{{ $name }}" class="mb-1 block text-sm font-medium">{{ $label }}</label>
    <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}"
           value="{{ $type !== 'password' ? old($name, $value) : '' }}"
           @if ($required) required @endif
           @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
           @error($name) aria-invalid="true" aria-describedby="{{ $name }}-error" @enderror
           {{ $attributes->class([
               'w-full rounded-lg border-control bg-surface text-ink shadow-sm focus:border-ring focus:ring-ring',
           ]) }}>
    @error($name)
        <p id="{{ $name }}-error" class="mt-1 text-sm text-danger-subtle-fg">{{ $message }}</p>
    @enderror
</div>
