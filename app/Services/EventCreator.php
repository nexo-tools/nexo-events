<?php

namespace App\Services;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;

/**
 * Creating an event, in one place. Extracted from EventController::store when
 * the integration API arrived (ADR-010 §6): two entry points that "follow the
 * same rules" only really do while the rules are one piece of code.
 */
class EventCreator
{
    /**
     * @param  array<string, mixed>  $attributes  already validated (EventRequest)
     * @param  ?string  $externalRef  the consumer's own key, API only. Guarded on
     *                                the model on purpose — direct set, never mass-assigned,
     *                                so no request body can ever repoint it.
     */
    public function create(User $organizer, array $attributes, ?string $externalRef = null): Event
    {
        $event = new Event($attributes);
        $event->slug = Event::uniqueSlugFor((string) ($attributes['title'] ?? ''));
        $event->status = EventStatus::Draft;
        $event->external_ref = $externalRef;
        $organizer->events()->save($event);

        return $event;
    }
}
