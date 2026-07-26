<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EventStatus;
use App\Models\Event;
use Illuminate\Console\Command;

/**
 * The abuse kill-switch (ADR-007 §4). CLI rather than an admin panel: a panel
 * would mean a whole privilege tier and attack surface to serve one operator —
 * see SPEC-abuse. Whoever can reach the server is already the operator.
 */
class KillEvent extends Command
{
    protected $signature = 'events:kill {slug : The event slug} {--reason= : Why it is being taken down}';

    protected $description = 'Take a reported event down: hides its public page, closes registration and stops its tickets at the door';

    public function handle(): int
    {
        $event = Event::query()->where('slug', $this->argument('slug'))->first();

        if ($event === null) {
            $this->error("No event with slug \"{$this->argument('slug')}\".");

            return self::FAILURE;
        }

        if ($event->status === EventStatus::Killed) {
            // Idempotent, and deliberately non-destructive: re-killing must not
            // overwrite the original reason or the status we have to restore to.
            $this->warn("Already killed on {$event->killed_at} — reason and previous status left untouched.");

            return self::SUCCESS;
        }

        $event->forceFill([
            'status_before_kill' => $event->status->value,
            'killed_at' => now(),
            'kill_reason' => (string) ($this->option('reason') ?: 'not stated'),
            'status' => EventStatus::Killed->value,
        ])->save();

        $this->info("Killed \"{$event->title}\" (was {$event->status_before_kill}).");
        $this->line("Undo: php artisan events:restore {$event->slug}");

        return self::SUCCESS;
    }
}
