<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EventStatus;
use App\Models\Event;
use Illuminate\Console\Command;

/**
 * Undo a kill (ADR-007 §4: the switch is reversible). Restores the status the
 * event actually had before, never a hardcoded one — otherwise restoring a
 * killed *draft* would silently publish it.
 */
class RestoreEvent extends Command
{
    protected $signature = 'events:restore {slug : The event slug}';

    protected $description = 'Undo a kill: puts the event back in the status it had before';

    public function handle(): int
    {
        $event = Event::query()->where('slug', $this->argument('slug'))->first();

        if ($event === null) {
            $this->error("No event with slug \"{$this->argument('slug')}\".");

            return self::FAILURE;
        }

        if ($event->status !== EventStatus::Killed) {
            $this->error("\"{$event->title}\" is not killed (status: {$event->status->value}).");

            return self::FAILURE;
        }

        $previous = EventStatus::tryFrom((string) $event->status_before_kill) ?? EventStatus::Draft;

        $event->forceFill([
            'status' => $previous->value,
            'killed_at' => null,
            'kill_reason' => null,
            'status_before_kill' => null,
        ])->save();

        $this->info("Restored \"{$event->title}\" to {$previous->value}.");

        return self::SUCCESS;
    }
}
