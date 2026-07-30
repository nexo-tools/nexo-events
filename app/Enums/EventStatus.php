<?php

namespace App\Enums;

enum EventStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Closed = 'closed';       // registration closed (manual or sold out), event still on
    case Cancelled = 'cancelled'; // organizer cancelled the event
    case Killed = 'killed';       // admin kill-switch (abuse)

    /** What a human is shown. The backing value is storage, never UI copy. */
    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Published => __('Published'),
            self::Closed => __('Registration closed'),
            self::Cancelled => __('Cancelled'),
            self::Killed => __('Blocked'),
        };
    }

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
