<?php

// Guardian: a 500 reaches the operator, exactly once per distinct failure —
// and a queued job only once it gives up.
//
// Why it exists: the ecosystem has no error tracker by design, so this mail is
// the only signal an operator gets. Three things can quietly break it and none
// shows up anywhere — the handler not being wired at all; the dedupe window
// failing, which turns one bad deploy into four thousand emails; and mailing
// every attempt of a job that is retried and then works (2026-11-23: one SMTP
// hiccup on a ticket mail, delivered by the next attempt, mailed the operator
// about a problem that had already fixed itself). Each one ends with a person
// who from then on ignores the inbox.
//
// The job tests run a real worker (`queue:work --once` on the database queue),
// not hand-fired events: what they pin down is the order in which the worker
// reports an attempt and fails a job, which is exactly what a fake would assume.
//
// Copy into tests/Feature/Nexo/.

use App\Mail\OperatorAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/** A queued job that fails its first $failures attempts, then works. */
final class OperatorAlertFlakyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $failures) {}

    public function handle(): void
    {
        if ($this->attempts() <= $this->failures) {
            throw new RuntimeException('the mail server said: try again later');
        }
    }
}

/** Runs one job off the database queue, the way the scheduled drain does. */
function workOneOperatorAlertJob(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 3, '--sleep' => 0]);
}

/** Forces the app to report an exception the way the framework would. */
function reportOperatorException(Throwable $e): void
{
    app(ExceptionHandler::class)->report($e);
}

beforeEach(function () {
    // The handler is off in tests by default (a suite must not mail anybody);
    // these tests turn it on deliberately and put a real recipient in place.
    config()->set('nexo.ops_mail', true);
    config()->set('nexo.support_email', 'operator@example.com');
    Cache::flush();
});

it('mails the operator when something breaks', function () {
    Mail::fake();

    reportOperatorException(new RuntimeException('the database went away'));

    Mail::assertQueued(OperatorAlert::class, fn (OperatorAlert $mail): bool => $mail->hasTo('operator@example.com'));
});

it('sends one mail per distinct failure, however many times it happens', function () {
    Mail::fake();

    $throw = fn () => reportOperatorException(new RuntimeException('the database went away'));

    $throw();
    $throw();
    $throw();

    // A loop throwing the same error four thousand times must produce one mail:
    // an operator whose inbox is flooded is an operator who stops reading it.
    Mail::assertQueuedCount(1);
});

it('still reports a different failure inside the same window', function () {
    Mail::fake();

    reportOperatorException(new RuntimeException('the database went away'));
    reportOperatorException(new LogicException('something else entirely'));

    Mail::assertQueuedCount(2);
});

it('says nothing when the operator turned it off', function () {
    Mail::fake();
    config()->set('nexo.ops_mail', false);

    reportOperatorException(new RuntimeException('the database went away'));

    // Off by default: a freshly cloned instance must not start mailing anybody.
    Mail::assertNothingQueued();
});

it('says nothing about a queued job attempt that is retried and then works', function () {
    Mail::fake();
    config()->set('queue.default', 'database');

    OperatorAlertFlakyJob::dispatch(failures: 2);

    workOneOperatorAlertJob(); // attempt 1 fails, released
    workOneOperatorAlertJob(); // attempt 2 fails, released
    workOneOperatorAlertJob(); // attempt 3 works

    Mail::assertNothingQueued();
    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('mails the operator once when a queued job gives up, naming the job', function () {
    Mail::fake();
    config()->set('queue.default', 'database');

    OperatorAlertFlakyJob::dispatch(failures: 3);

    workOneOperatorAlertJob();
    workOneOperatorAlertJob();
    Mail::assertNothingQueued(); // two attempts down, one to go: not news yet

    workOneOperatorAlertJob(); // the last attempt fails: now it is

    Mail::assertQueuedCount(1);
    Mail::assertQueued(OperatorAlert::class, fn (OperatorAlert $mail): bool => $mail->hasTo('operator@example.com')
        && $mail->job === OperatorAlertFlakyJob::class.' — 3 attempts');
    expect(DB::table('failed_jobs')->count())->toBe(1);
});

it('mails once when a job fails on the sync queue inside a request', function () {
    Mail::fake();

    try {
        OperatorAlertFlakyJob::dispatchSync(failures: 1);
    } catch (RuntimeException $e) {
        // The request's own handler reports the same exception afterwards.
        reportOperatorException($e);
    }

    Mail::assertQueuedCount(1);
    Mail::assertQueued(OperatorAlert::class, fn (OperatorAlert $mail): bool => $mail->job !== null);
});

it('never alerts about the alert mail itself giving up', function () {
    Mail::fake();

    $job = new SyncJob(app(), (string) json_encode([
        'uuid' => 'operator-alert-test',
        'displayName' => OperatorAlert::class,
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'data' => ['commandName' => 'Illuminate\\Mail\\SendQueuedMailable', 'command' => ''],
    ]), 'sync', 'default');

    // The mail server is what broke: alerting about it would queue another
    // mail through the same broken server, forever.
    event(new JobFailed('sync', $job, new RuntimeException('Connection could not be established with host smtp')));

    Mail::assertNothingQueued();
});
