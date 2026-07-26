<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EventStatus;
use App\Models\Event;
use Illuminate\Console\Command;

/**
 * Refuses to let a deploy run while an event is at its door.
 *
 * SCOPE's operating principle: *a downed instance during a live event leaves
 * people at the door*. Unlike most web apps, this one has moments that cannot
 * be retried — an attendee standing outside a venue with a spinning browser
 * will not "try again later", and `artisan down` counts as downtime.
 *
 * The window is inferred from `starts_at`: there is no `ends_at` column, so it
 * spans a configurable margin before (doors open, people scanning early) and
 * after (the event itself, late arrivals).
 *
 * Exit codes: 0 = clear to deploy, 1 = an event is in its window.
 */
class DoorGuard extends Command
{
    protected $signature = 'events:door-guard {--json : Machine-readable output}';

    protected $description = 'Exit non-zero if any event is currently inside its door window (deploy freeze)';

    public function handle(): int
    {
        $before = (int) config('nexo.door_guard.minutes_before', 120);
        $after = (int) config('nexo.door_guard.minutes_after', 360);

        $active = Event::query()
            ->where('status', EventStatus::Published->value)
            ->whereBetween('starts_at', [now()->subMinutes($after), now()->addMinutes($before)])
            ->orderBy('starts_at')
            ->get(['id', 'title', 'slug', 'starts_at']);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'blocked' => $active->isNotEmpty(),
                'events' => $active->map(fn (Event $e): array => [
                    'slug' => $e->slug,
                    'title' => $e->title,
                    'starts_at' => $e->starts_at->toIso8601String(),
                ])->all(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $active->isEmpty() ? self::SUCCESS : self::FAILURE;
        }

        if ($active->isEmpty()) {
            $this->info('No event is in its door window — safe to deploy.');

            return self::SUCCESS;
        }

        $this->error('DEPLOY BLOCKED — '.$active->count().' event(s) at the door right now:');
        foreach ($active as $event) {
            $this->line("  · {$event->title} ({$event->slug}) — starts {$event->starts_at->format('d/m/Y H:i')}");
        }
        $this->newLine();
        $this->line('Taking the app down now strands these people outside a venue.');
        $this->line('Wait for the window to close, or override deliberately with DEPLOY_FORCE=1.');

        return self::FAILURE;
    }
}
