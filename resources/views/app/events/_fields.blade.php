@php($e = $event ?? null)
@php($input = 'mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800')

<div>
    <label class="block text-sm font-medium">{{ __('Título') }}</label>
    <input name="title" value="{{ old('title', $e?->title) }}" required maxlength="180" class="{{ $input }}">
    @error('title')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
</div>

<div>
    <label class="block text-sm font-medium">{{ __('Descripción') }}</label>
    <textarea name="description" rows="3" class="{{ $input }}">{{ old('description', $e?->description) }}</textarea>
    @error('description')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
</div>

<div>
    <label class="block text-sm font-medium">{{ __('Fecha y hora') }}</label>
    <input type="datetime-local" name="starts_at" required class="{{ $input }}"
           value="{{ old('starts_at', $e?->starts_at?->format('Y-m-d\TH:i')) }}">
    @error('starts_at')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
</div>

<div>
    <label class="block text-sm font-medium">{{ __('Lugar (opcional)') }}</label>
    <input name="venue" value="{{ old('venue', $e?->venue) }}" class="{{ $input }}">
</div>

<div>
    <label class="block text-sm font-medium">{{ __('Cupo (opcional)') }}</label>
    <input type="number" name="capacity" min="1" value="{{ old('capacity', $e?->capacity) }}" class="{{ $input }}">
    @error('capacity')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
</div>
