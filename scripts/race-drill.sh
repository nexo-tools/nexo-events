#!/usr/bin/env bash
# Concurrency drill against REAL MySQL (ADR-004's mandate).
#
# The Pest suite runs on SQLite, where `lockForUpdate()` is a no-op and two
# "concurrent" registrations are really just two sequential ones. That proves
# the application logic, not the database guarantee the design leans on. This
# script races genuinely parallel processes against the MySQL the app actually
# uses, so the row lock and the unique constraints are the things under test.
#
#   ./scripts/race-drill.sh
#
# Requires the app container running and its DB reachable (see AGENTS.md).
set -euo pipefail

cd "$(dirname "$0")/.."
RUNS=${RUNS:-8}          # racers per round
ROUNDS=${ROUNDS:-3}      # rounds, because a race that passes once proves little
FAILURES=0

art() { docker compose exec -T laravel.test php artisan "$@"; }

echo "== Concurrency drill: ${ROUNDS} rounds x ${RUNS} racers, against MySQL =="

for round in $(seq 1 "$ROUNDS"); do
    echo
    echo "-- Round ${round} --"

    # ---------- 1. Capacity: one seat, many simultaneous registrations ----------
    SLUG=$(art tinker --execute '
        $u = App\Models\User::firstOrCreate(["email" => "race@example.com"], ["name" => "Race", "password" => "x"]);
        $e = new App\Models\Event();
        $e->organizer_id = $u->id; $e->title = "Race"; $e->slug = "race-".bin2hex(random_bytes(4));
        $e->starts_at = now()->addDay(); $e->venue = "V"; $e->capacity = 1;
        $e->status = App\Enums\EventStatus::Published; $e->save();
        echo $e->slug;
    ' 2>/dev/null | tr -d '\r\n ')

    for i in $(seq 1 "$RUNS"); do
        art tinker --execute "
            \$e = App\Models\Event::where('slug', '${SLUG}')->firstOrFail();
            app(App\Services\EventRegistrar::class)->register(\$e, 'R${i}', 'r${i}-${SLUG}@example.com');
        " >/dev/null 2>&1 &
    done
    wait

    TICKETS=$(art tinker --execute "
        echo App\Models\Event::where('slug', '${SLUG}')->firstOrFail()->tickets()->count();
    " 2>/dev/null | tr -d '\r\n ')

    if [ "$TICKETS" = "1" ]; then
        echo "  capacity : OK   (${RUNS} racers for 1 seat -> ${TICKETS} ticket)"
    else
        echo "  capacity : FAIL (${RUNS} racers for 1 seat -> ${TICKETS} tickets, expected 1)"
        FAILURES=$((FAILURES + 1))
    fi

    # ---------- 2. Check-in: one ticket, many simultaneous scans ----------
    TOKEN=$(art tinker --execute '
        $u = App\Models\User::firstOrCreate(["email" => "race@example.com"], ["name" => "Race", "password" => "x"]);
        $e = new App\Models\Event();
        $e->organizer_id = $u->id; $e->title = "Race2"; $e->slug = "race2-".bin2hex(random_bytes(4));
        $e->starts_at = now()->addDay(); $e->venue = "V";
        $e->status = App\Enums\EventStatus::Published; $e->save();
        echo app(App\Services\EventRegistrar::class)->register($e, "Ana", "ana-".$e->slug."@example.com")["token"];
    ' 2>/dev/null | tr -d '\r\n ')

    for i in $(seq 1 "$RUNS"); do
        art tinker --execute "
            app(App\Services\TicketCheckin::class)->checkInByToken('${TOKEN}');
        " >/dev/null 2>&1 &
    done
    wait

    ENTRIES=$(art tinker --execute "
        \$t = App\Models\Ticket::where('token_hash', hash('sha256', '${TOKEN}'))->firstOrFail();
        echo App\Models\Checkin::where('ticket_id', \$t->id)->count();
    " 2>/dev/null | tr -d '\r\n ')

    if [ "$ENTRIES" = "1" ]; then
        echo "  check-in : OK   (${RUNS} simultaneous scans -> ${ENTRIES} entry)"
    else
        echo "  check-in : FAIL (${RUNS} simultaneous scans -> ${ENTRIES} entries, expected 1)"
        FAILURES=$((FAILURES + 1))
    fi
done

echo
if [ "$FAILURES" -eq 0 ]; then
    echo "== PASS: no double entry and no oversell under real concurrency =="
    exit 0
fi
echo "== FAIL: ${FAILURES} racing round(s) broke the guarantee =="
exit 1
