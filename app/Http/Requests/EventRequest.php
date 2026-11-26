<?php

namespace App\Http\Requests;

use App\Models\Event;
use Closure;
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
            // PLAN 10.14: the exact address on its own, private like access_info,
            // so the ticket can offer copy and open-in-a-map buttons.
            'access_address' => ['nullable', 'string', 'max:255'],
            'access_info' => ['nullable', 'string', 'max:1000'],
            'payment_info' => ['nullable', 'string', 'max:300'],
            // PLAN 10.15: an optional "More information" link, shown on the
            // public page as a button with its domain. Absolute https only:
            // http, javascript:, data:, mailto: and relative paths are refused
            // (anyone can create events, and the link opens from this domain).
            // Trimmed and empty → null by the framework's global middleware.
            'info_url' => ['nullable', 'string', 'max:2048', 'url:https'],
            // The same floor as the summary's capacity form
            // (EventManagementController::capacity): lowering it under the
            // tickets already out would not un-issue anybody's ticket, only
            // make the counters lie (SPEC-event-panel AC-PANEL-6).
            'capacity' => ['nullable', 'integer', 'min:1', 'max:1000000', $this->capacityFloor()],
            // API only — the consumer's own key for the event, which is what
            // makes creation idempotent. The web form has no such field, and the
            // column is outside Event's #[Fillable], so it can only ever be set
            // by EventCreator (SPEC "Idempotent creation").
            'external_ref' => ['nullable', 'string', 'max:190'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        // The framework's "must be a valid URL" does not say what is missing;
        // the usual mistake is a link without https:// (or with http://).
        return [
            'info_url.url' => __('The link has to be a full web address starting with https://.'),
        ];
    }

    /**
     * Not below the tickets already issued (revoked ones do not count) — only
     * when editing an existing event, and only when the value changes: the
     * edit form always posts the stored capacity back, and an event whose
     * older data sits under the floor must still be editable.
     */
    private function capacityFloor(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $event = $this->editedEvent();

            if ($event === null || ! is_numeric($value) || (int) $value === $event->capacity) {
                return;
            }

            $issued = $event->issuedTicketsCount();

            if ((int) $value < $issued) {
                $fail(__('The capacity cannot be lower than the :count tickets already issued.', ['count' => $issued]));
            }
        };
    }

    /**
     * The event being edited: bound by the web route, or by id on the API —
     * looked up among the caller's own events only, so validation never
     * reveals anything about somebody else's (the controller answers 404).
     */
    private function editedEvent(): ?Event
    {
        $param = $this->route('event');

        if ($param instanceof Event) {
            return $param;
        }

        $user = $this->user();

        if (is_string($param) && $user !== null) {
            return Event::query()->where('organizer_id', $user->getAuthIdentifier())->whereKey($param)->first();
        }

        return null;
    }
}
