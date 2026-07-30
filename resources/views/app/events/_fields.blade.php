@php($e = $event ?? null)

<x-field :label="__('Título')" name="title" :value="$e?->title" required maxlength="180" />

<x-textarea :label="__('Descripción')" name="description" :value="$e?->description" />

<x-field :label="__('Fecha y hora')" name="starts_at" type="datetime-local" required
         :value="$e?->starts_at?->format('Y-m-d\TH:i')" />

<x-field :label="__('Lugar (opcional)')" name="venue" :value="$e?->venue" />

<x-field :label="__('Cupo (opcional)')" name="capacity" type="number" min="1" :value="$e?->capacity" />
