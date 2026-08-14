<?php

namespace Database\Factories;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiToken>
 */
class ApiTokenFactory extends Factory
{
    /**
     * By default the row holds the hash of a token nobody kept — which is the
     * normal state of every token in the database. A test that needs to send
     * requests generates its own raw value and hashes it in.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'token_hash' => ApiToken::hashFor(ApiToken::newRawToken()),
            'abilities' => ApiToken::ABILITIES,
            'last_used_at' => null,
            'revoked_at' => null,
        ];
    }

    /** @param  array<int, string>  $abilities */
    public function withAbilities(array $abilities): static
    {
        return $this->state(['abilities' => $abilities]);
    }

    public function revoked(): static
    {
        return $this->state(['revoked_at' => now()]);
    }
}
