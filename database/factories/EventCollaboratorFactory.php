<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventCollaborator;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EventCollaborator>
 */
class EventCollaboratorFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'email' => fake()->unique()->safeEmail(),
            'invite_token_hash' => EventCollaborator::hashFor(Str::random(40)),
            'invited_at' => now(),
            'expires_at' => now()->addDays(7),
        ];
    }

    /** Accepted by $user (the row takes that user's email). */
    public function acceptedBy(User $user): static
    {
        return $this->state([
            'user_id' => $user->id,
            'email' => strtolower($user->email),
            'invite_token_hash' => null,
            'accepted_at' => now(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(['revoked_at' => now(), 'invite_token_hash' => null]);
    }
}
