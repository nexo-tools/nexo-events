<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Shared hosting cannot run a long-lived queue worker (no daemons, ADR-002), so
 * the scheduler drains the database queue in short bursts instead. Production
 * needs the one standard cron entry:
 *
 *     * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
 *
 * Without it no ticket email ever leaves, while the app looks perfectly healthy
 * (see docs/DELIVERABILITY.md).
 *
 * --stop-when-empty exits once the queue drains so runs never pile up;
 * --max-time keeps a run inside its minute; withoutOverlapping is the belt to
 * that braces.
 *
 * (This file previously scheduled `nexo:send-reminders`, a command that never
 * existed in this app — reminders are explicitly out of v1 scope, SCOPE "Out".
 * Every `schedule:run` was erroring on it.)
 */
Schedule::command('queue:work --stop-when-empty --tries=3 --max-time=55')
    ->everyMinute()
    ->withoutOverlapping();

/*
 * Pre-event reminders. Hourly rather than daily so the window stays honest for
 * an event created (or a ticket issued) the same morning; the command is
 * idempotent per ticket, so a re-run mails nobody twice.
 */
Schedule::command('events:send-reminders')->hourly();
