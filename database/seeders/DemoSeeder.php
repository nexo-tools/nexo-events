<?php

namespace Database\Seeders;

use App\Enums\EventStatus;
use App\Enums\TicketStatus;
use App\Models\Checkin;
use App\Models\Event;
use App\Models\EventView;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Demo data for the landing screenshots (design.md, "Family": real captures from
 * a LOCAL instance, never production). The landing shows three screens — the
 * event being created, its public page taking registrations, and the door — so
 * the fixture has to make all three show a product in use rather than an empty
 * state.
 *
 * Deliberately NOT registered in DatabaseSeeder, and deliberately NOT going
 * through EventRegistrar: the registrar queues confirmation mail, and seeding
 * eight attendees is not a reason to send eight emails. Rows are written
 * directly instead.
 *
 * Deterministic: same events, same slugs, same attendees on every run, because
 * a screenshot that changes when nothing changed is a diff nobody can review.
 */
class DemoSeeder extends Seeder
{
    use WithoutModelEvents;

    /** The window of page views the dashboard counters show. */
    private const DAYS = 14;

    /**
     * A known token so a QR can be re-generated for a demo without guessing.
     * Only its SHA-256 is stored — the raw token never touches the database
     * (ADR-004/008), which is the whole point of the `token_hash` column.
     */
    private const DEMO_TOKEN = 'demotoken0demotoken0demotoken0demotoken0';

    /**
     * Three published events, near enough to read as a live calendar.
     *
     * @var list<array{title:string, venue:string, days:int, capacity:?int, description:string}>
     */
    private const EVENTS = [
        [
            'title' => 'Meetup de producto',
            'venue' => 'Sala Norte, Centro de Convenciones',
            'days' => 3,
            'capacity' => 100,
            'description' => 'Charlas cortas y preguntas abiertas sobre cómo se decide qué construir. Entrada libre con registro previo.',
        ],
        [
            'title' => 'Taller de introducción',
            'venue' => 'Aula 2, Espacio Cowork',
            'days' => 10,
            'capacity' => 30,
            'description' => 'Dos horas prácticas para arrancar de cero. Traé tu computadora; el resto lo ponemos nosotros.',
        ],
        [
            'title' => 'Demo day',
            'venue' => 'Auditorio Sur',
            'days' => 21,
            'capacity' => null,
            'description' => 'Ocho equipos muestran lo que construyeron en el último trimestre. Sin cupo: la sala es grande.',
        ],
    ];

    /**
     * Eight attendees for the first event, four of them already through the
     * door — so "Registrados" shows both states side by side instead of one.
     *
     * @var list<array{name:string, checkedIn:bool}>
     */
    private const ATTENDEES = [
        ['name' => 'Ana Duarte', 'checkedIn' => true],
        ['name' => 'Bruno Salas', 'checkedIn' => true],
        ['name' => 'Carla Nieves', 'checkedIn' => true],
        ['name' => 'Diego Ferrer', 'checkedIn' => true],
        ['name' => 'Elena Ríos', 'checkedIn' => false],
        ['name' => 'Facundo Pérez', 'checkedIn' => false],
        ['name' => 'Gabriela Ortiz', 'checkedIn' => false],
        ['name' => 'Hernán Vidal', 'checkedIn' => false],
    ];

    /**
     * Unique visitor-days on the first event's public page, oldest first. Hand
     * written rather than random: a shared event page peaks when the link goes
     * out and decays, and a counter that says that reads as a product.
     *
     * @var list<int>
     */
    private const VIEWS_PER_DAY = [3, 5, 4, 9, 26, 19, 14, 11, 8, 12, 17, 9, 6, 7];

    public function run(): void
    {
        // The factory picks a fake() name; the demo organizer needs a fixed one,
        // or every re-capture changes the account menu.
        $organizer = User::firstOrCreate(
            ['email' => 'demo@example.com'],
            ['name' => 'Demo', 'password' => Hash::make('password'), 'email_verified_at' => now()->subDays(60)->startOfDay()],
        );

        $events = [];
        foreach (self::EVENTS as $position => $data) {
            $slug = Str::slug($data['title']);

            // organizer_id and slug are NOT in the model's fillable list, and
            // nothing generates the slug on create — assigning them directly is
            // the difference between a seeded event and a NOT NULL violation.
            $event = Event::firstOrNew(['slug' => $slug]);
            $event->organizer_id = $organizer->id;
            $event->slug = $slug;
            $event->title = $data['title'];
            $event->description = $data['description'];
            $event->venue = $data['venue'];
            $event->capacity = $data['capacity'];
            $event->starts_at = now()->addDays($data['days'])->setTime(19, 0);
            $event->status = EventStatus::Published;
            $event->save();

            $events[$position] = $event;
        }

        $first = $events[0];
        $first->tickets()->delete();

        foreach (self::ATTENDEES as $i => $attendee) {
            $token = $i === 0
                ? self::DEMO_TOKEN
                : 'demo-ticket-'.$first->id.'-'.$i.'-fixed-token-value';

            $ticket = new Ticket;
            $ticket->event_id = $first->id;
            $ticket->attendee_name = $attendee['name'];
            $ticket->attendee_email = 'cliente'.($i + 1).'@example.com';
            // token_hash is guarded on purpose: the raw token is emailed and
            // shown once, never stored. Assigned as an attribute, never
            // mass-assigned.
            $ticket->token_hash = hash('sha256', $token);
            $ticket->status = TicketStatus::Valid;
            $ticket->save();

            if ($attendee['checkedIn']) {
                Checkin::create([
                    'ticket_id' => $ticket->id,
                    'checked_by' => $organizer->id,
                    'checked_at' => now()->subMinutes(90 - ($i * 12)),
                ]);
            }
        }

        // One row per visitor-day: the unique constraint is the dedupe, so the
        // hashes have to differ within a day and may repeat across days.
        $first->views()->delete();

        $rows = [];
        foreach (self::VIEWS_PER_DAY as $offset => $count) {
            $day = now()->subDays(self::DAYS - 1 - $offset)->startOfDay();

            for ($i = 0; $i < $count; $i++) {
                $rows[] = [
                    'event_id' => $first->id,
                    'visitor_hash' => hash('sha256', 'demo-view-'.$first->id.'-'.$i.'-'.$day->toDateString()),
                    'viewed_on' => $day->toDateString(),
                    'created_at' => $day->copy()->addMinutes(9 * 60 + ($i * 23) % (10 * 60)),
                ];
            }
        }

        EventView::insert($rows);
    }
}
