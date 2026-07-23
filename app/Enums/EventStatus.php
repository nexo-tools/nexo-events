<?php

namespace App\Enums;

enum EventStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Closed = 'closed';       // registration closed (manual or sold out), event still on
    case Cancelled = 'cancelled'; // organizer cancelled the event
    case Killed = 'killed';       // admin kill-switch (abuse)

    /** Can attendees register right now? */
    public function acceptsRegistrations(): bool
    {
        return $this === self::Published;
    }

    /** Is the public page visible (published/closed/cancelled show; draft/killed hide)? */
    public function isPublic(): bool
    {
        return in_array($this, [self::Published, self::Closed, self::Cancelled], true);
    }
}
