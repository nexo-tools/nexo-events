<?php

use Illuminate\Support\Facades\Artisan;
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
 * Every task here runs INLINE (Schedule::call + Artisan::call), never as a
 * Schedule::command subprocess: this hosting disables proc_open/exec, so a
 * scheduled subprocess dies before it starts. That is exactly how the drain
 * broke in production between 2026-07-27 (Hostinger moved PHP to 8.5) and
 * 2026-08-02 — a ticket email sat queued for days while the app looked fine.
 *
 * --stop-when-empty exits once the queue drains so runs never pile up;
 * --max-time keeps a run inside its minute; withoutOverlapping is the belt to
 * that braces.
 *
 * (This file previously scheduled `nexo:send-reminders`, a command that never
 * existed in this app — reminders are explicitly out of v1 scope, SCOPE "Out".
 * Every `schedule:run` was erroring on it.)
 */
Schedule::call(fn () => Artisan::call('queue:work --stop-when-empty --tries=3 --max-time=55'))
    ->name('queue-drain')
    ->everyMinute()
    ->withoutOverlapping();

/*
 * Pre-event reminders. Hourly rather than daily so the window stays honest for
 * an event created (or a ticket issued) the same morning; the command is
 * idempotent per ticket, so a re-run mails nobody twice.
 */
Schedule::call(fn () => Artisan::call('events:send-reminders'))
    ->name('send-reminders')
    ->hourly();
