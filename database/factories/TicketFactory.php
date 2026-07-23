<?php

namespace Database\Factories;

use App\Enums\TicketStatus;
use App\Models\Event;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'attendee_name' => fake()->name(),
            'attendee_email' => fake()->unique()->safeEmail(),
            'token_hash' => hash('sha256', Str::random(40)),
            'status' => TicketStatus::Valid,
        ];
    }
}
