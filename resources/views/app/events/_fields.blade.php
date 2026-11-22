@php($e = $event ?? null)

<x-field :label="__('Title')" name="title" :value="$e?->title" required maxlength="180" />

<x-textarea :label="__('Description')" name="description" :value="$e?->description" />

{{-- PLAN 10.15: an optional link for more information, shown on the public
     page as a button with the domain it leads to. https only (EventRequest). --}}
<x-field :label="__('Link for more information (optional)')" name="info_url" type="url" :value="$e?->info_url" maxlength="2048" placeholder="https://"
         :hint="__('A link to your site with more information about the event. It has to start with https://. It is shown as a “More information” button.')" />

<x-field :label="__('Date and time')" name="starts_at" type="datetime-local" required
         :value="$e?->starts_at?->format('Y-m-d\TH:i')" />

<x-field :label="__('Venue (optional)')" name="venue" :value="$e?->venue" />

<x-field :label="__('Capacity (optional)')" name="capacity" type="number" min="1" :value="$e?->capacity" />

{{-- ADR-012. Plain text both. access_info is the one field on this form that
     never reaches the public page — the help says so, and also that "not
     published" is not "secret": registering is free. --}}
{{-- PLAN 10.14: the exact address on its own, private exactly like
     access_info, so the ticket can offer copy and open-in-a-map. --}}
<x-field :label="__('Address (only for ticket holders, optional)')" name="access_address" :value="$e?->access_address" maxlength="255"
         :hint="__('Shown on the ticket and in the emails, with buttons to copy it and open it in a map. It is not published on the event page.')" />

<x-textarea :label="__('Only for ticket holders (optional)')" name="access_info" :value="$e?->access_info" maxlength="1000"
            :hint="__('Exact address, how to get in, what to bring. It is not published on the event page: it appears on the ticket and in the emails. Anyone who registers can read it.')" />

<x-field :label="__('Price and how to pay (optional)')" name="payment_info" :value="$e?->payment_info" maxlength="300"
         :hint="__('Shown on the public page, on the ticket and at the door. Nexo Events does not collect payments.')" />
