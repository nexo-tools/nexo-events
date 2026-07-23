<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    // Feature tests render Blade without a built frontend; skip the Vite manifest.
    ->beforeEach(fn () => $this->withoutVite())
    ->in('Feature');
