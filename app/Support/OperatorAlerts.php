<?php

declare(strict_types=1);

namespace App\Support;

use App\Mail\OperatorAlert;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;
use WeakMap;

/**
 * When the operator gets a "something broke" mail (templates/nexo-ops, O1).
 *
 * - Outside a queued job: every exception the framework reports.
 * - Inside a queued job: only when the job gives up — its last attempt failed,
 *   or it was failed outright. An attempt that is going to be retried is not
 *   news: on 2026-11-23 one SMTP hiccup (a TransportException on a ticket
 *   mail that the next attempt delivered) mailed the operator about a problem
 *   that had already fixed itself. An operator who learns that these mails are
 *   noise stops reading them, and then misses the one that is not.
 *
 * How it tells the two apart: the queue fires JobExceptionOccurred for every
 * failed attempt BEFORE the worker reports the exception (Worker::runJob), and
 * fires JobFailed when the job is given up. So the exception object of an
 * attempt is remembered here, the report of it is skipped, and the JobFailed
 * listener is the one that mails. It holds on the sync driver too, where the
 * job fails inside the web request: the request's own report of the same
 * exception is skipped, so the failure is mailed once, with the job named.
 *
 * Every mail still goes through the same dedupe (exception class + file +
 * line, 15 minutes, Cache::add being atomic) and the same kill-switch.
 */
final class OperatorAlerts
{
    /** @var WeakMap<Throwable, bool>|null */
    private static ?WeakMap $jobAttempts = null;

    /** Wire the queue listeners. Called once from bootstrap/app.php (->booted). */
    public static function listen(Dispatcher $events): void
    {
        $events->listen(JobExceptionOccurred::class, static function (JobExceptionOccurred $event): void {
            self::jobAttempts()[$event->exception] = true;
        });

        $events->listen(JobFailed::class, static function (JobFailed $event): void {
            $job = $event->job->resolveName();

            // The alert mail itself giving up (the mail server is what broke)
            // must not queue another alert about it: that is a loop that only
            // the dedupe window would slow down, never stop.
            if ($job === OperatorAlert::class) {
                return;
            }

            self::send($event->exception, null, $job.' — '.$event->job->attempts().' '.($event->job->attempts() === 1 ? 'attempt' : 'attempts'));
        });
    }

    /** The exception handler's report callback (bootstrap/app.php, ->withExceptions). */
    public static function report(Throwable $e): void
    {
        // A failed attempt of a queued job: JobFailed speaks for it if, and
        // only if, the job gives up.
        if (isset(self::jobAttempts()[$e])) {
            return;
        }

        self::send($e, app()->runningInConsole() ? null : request()->fullUrl());
    }

    private static function send(Throwable $e, ?string $url, ?string $job = null): void
    {
        // Off unless the operator turned it on — which is also what keeps a
        // suite quiet, since the flag is false in the testing environment.
        if (! config('nexo.ops_mail', false)) {
            return;
        }

        $recipient = (string) config('nexo.support_email');
        if ($recipient === '') {
            return;
        }

        // Dedupe by exception identity, not by message: a loop throwing the
        // same error 4.000 times must produce ONE mail. Cache::add is atomic,
        // so two workers racing still send one.
        $key = 'ops-mail:'.sha1($e::class.'|'.$e->getFile().'|'.$e->getLine());
        if (! Cache::add($key, true, now()->addMinutes(15))) {
            return;
        }

        Mail::to($recipient)->queue(OperatorAlert::fromThrowable($e, $url, $job));
    }

    /** @return WeakMap<Throwable, bool> */
    private static function jobAttempts(): WeakMap
    {
        // Weak on purpose: a long-running worker sees thousands of attempts,
        // and an exception nobody holds any more must not be kept alive here.
        return self::$jobAttempts ??= new WeakMap;
    }
}
