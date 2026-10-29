@php($e = $event ?? null)

<x-field :label="__('Title')" name="title" :value="$e?->title" required maxlength="180" />

<x-textarea :label="__('Description')" name="description" :value="$e?->description" />

<x-field :label="__('Date and time')" name="starts_at" type="datetime-local" required
         :value="$e?->starts_at?->format('Y-m-d\TH:i')" />

<x-field :label="__('Venue (optional)')" name="venue" :value="$e?->venue" />

<x-field :label="__('Capacity (optional)')" name="capacity" type="number" min="1" :value="$e?->capacity" />

{{-- ADR-012. Plain text both. access_info is the one field on this form that
     never reaches the public page — the help says so, and also that "not
     published" is not "secret": registering is free. --}}
<x-textarea :label="__('Only for ticket holders (optional)')" name="access_info" :value="$e?->access_info" maxlength="1000"
            :hint="__('Exact address, how to get in, what to bring. It is not published on the event page: it appears on the ticket and in the emails. Anyone who registers can read it.')" />

<x-field :label="__('Price and how to pay (optional)')" name="payment_info" :value="$e?->payment_info" maxlength="300"
         :hint="__('Shown on the public page, on the ticket and at the door. Nexo Events does not collect payments.')" />
