@props(['label', 'name', 'type' => 'text', 'value' => null, 'required' => false, 'autocomplete' => null, 'hint' => null])

@php($describedBy = trim(($hint ? $name.'-hint ' : '').($errors->has($name) ? $name.'-error' : '')))
<div>
    <label for="{{ $name }}" class="mb-1 block text-sm font-medium">{{ $label }}</label>
    <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}"
           value="{{ $type !== 'password' ? old($name, $value) : '' }}"
           @if ($required) required @endif
           @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
           @error($name) aria-invalid="true" @enderror
           @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
           {{ $attributes->class([
               'w-full rounded-lg border-control bg-surface text-ink shadow-sm focus:border-ring focus:ring-ring',
           ]) }}>
    @if ($hint)
        <p id="{{ $name }}-hint" class="mt-1 text-xs text-muted">{{ $hint }}</p>
    @endif
    @error($name)
        <p id="{{ $name }}-error" class="mt-1 text-sm text-danger-subtle-fg">{{ $message }}</p>
    @enderror
</div>
