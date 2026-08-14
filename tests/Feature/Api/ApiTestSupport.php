<?php

// Shared support for the integration API suite. require_once'd by each test
// file — functions are guarded for repeat inclusion — so that running one file
// on its own behaves like running the whole suite.

use App\Models\ApiToken;
use App\Models\User;

if (! function_exists('apiToken')) {
    /**
     * Issues a token and returns the raw value, which is the only moment it
     * exists: the application stores nothing but its hash.
     *
     * @param  array<int, string>  $abilities
     */
    function apiToken(User $user, array $abilities = ApiToken::ABILITIES): string
    {
        $raw = ApiToken::newRawToken();

        ApiToken::factory()->for($user)->create([
            'token_hash' => ApiToken::hashFor($raw),
            'abilities' => $abilities,
        ]);

        return $raw;
    }
}

if (! function_exists('bearer')) {
    /** @return array<string, string> */
    function bearer(string $raw): array
    {
        return ['Authorization' => 'Bearer '.$raw];
    }
}
