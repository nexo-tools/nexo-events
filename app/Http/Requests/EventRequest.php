<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One rule set for the web form and the integration API (ADR-010 §6). Two rule
 * sets would drift, and the day they did, the API would be the surface nobody
 * was looking at.
 */
class EventRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        // PATCH is a partial update by contract (AC-API-7): a field left out
        // keeps its stored value. `sometimes` is the only thing that changes —
        // types, lengths and bounds apply identically to whatever IS sent. The
        // web form posts POST/PUT, so its validation is untouched.
        $presence = $this->isMethod('PATCH') ? 'sometimes' : 'required';

        return [
            'title' => [$presence, 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:5000'],
            'starts_at' => [$presence, 'date'],
            'venue' => ['nullable', 'string', 'max:255'],
            // ADR-012: plain text, escaped on output. access_info is shown only to
            // ticket holders; payment_info is public and describes a price the
            // platform never collects.
            'access_info' => ['nullable', 'string', 'max:1000'],
            'payment_info' => ['nullable', 'string', 'max:300'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            // API only — the consumer's own key for the event, which is what
            // makes creation idempotent. The web form has no such field, and the
            // column is outside Event's #[Fillable], so it can only ever be set
            // by EventCreator (SPEC "Idempotent creation").
            'external_ref' => ['nullable', 'string', 'max:190'],
        ];
    }
}
