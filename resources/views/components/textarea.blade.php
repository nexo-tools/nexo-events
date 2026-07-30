@props(['label', 'name', 'value' => null, 'rows' => 3, 'required' => false])

<div>
    <label for="{{ $name }}" class="mb-1 block text-sm font-medium">{{ $label }}</label>
    <textarea id="{{ $name }}" name="{{ $name }}" rows="{{ $rows }}"
              @if ($required) required @endif
              @error($name) aria-invalid="true" aria-describedby="{{ $name }}-error" @enderror
              {{ $attributes->class([
                  'w-full rounded-lg border-control bg-surface text-ink shadow-sm focus:border-ring focus:ring-ring',
              ]) }}>{{ old($name, $value) }}</textarea>
    @error($name)
        <p id="{{ $name }}-error" class="mt-1 text-sm text-danger-subtle-fg">{{ $message }}</p>
    @enderror
</div>
