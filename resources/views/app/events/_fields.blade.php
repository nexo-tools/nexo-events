@php($e = $event ?? null)

<x-field :label="__('Title')" name="title" :value="$e?->title" required maxlength="180" />

<x-textarea :label="__('Description')" name="description" :value="$e?->description" />

<x-field :label="__('Date and time')" name="starts_at" type="datetime-local" required
         :value="$e?->starts_at?->format('Y-m-d\TH:i')" />

<x-field :label="__('Venue (optional)')" name="venue" :value="$e?->venue" />

<x-field :label="__('Capacity (optional)')" name="capacity" type="number" min="1" :value="$e?->capacity" />
