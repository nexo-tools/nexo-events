<?php

use App\Http\Controllers\Api\CollaboratorApiController;
use App\Http\Controllers\Api\EventApiController;
use App\Http\Controllers\Api\MeController;
use App\Models\ApiToken;
use Illuminate\Support\Facades\Route;

/*
 * Integration API v1 (ADR-010, docs/specs/SPEC-integration-api.md).
 *
 * These routes are ALWAYS registered — including on an instance that will never
 * turn the API on. Whether they answer is decided at runtime by the
 * `api.token` middleware, which 404s while NEXO_API_ENABLED is false. Doing it
 * the other way round (wrapping this file in an `if`) is the trap this project
 * already paid for once with SSO: `route:cache` bakes the absence in, and
 * flipping the env flag then changes nothing until somebody re-caches the
 * routes at exactly the wrong moment (DEPLOYMENT.md).
 *
 * Ids, never slugs: a title change must not break a consumer's stored
 * reference. Ownership is checked in the controller and answers 404, so the
 * API never reveals which event ids exist on this instance.
 */
Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('me', MeController::class)
        ->middleware(['api.token', 'throttle:nexo-api-read'])
        ->name('me');

    Route::middleware(['api.token:'.ApiToken::READ, 'throttle:nexo-api-read'])->group(function (): void {
        Route::get('events/{event}', [EventApiController::class, 'show'])->whereNumber('event')->name('events.show');
    });

    Route::middleware(['api.token:'.ApiToken::WRITE, 'throttle:nexo-api-write'])->group(function (): void {
        Route::post('events', [EventApiController::class, 'store'])->name('events.store');
        Route::patch('events/{event}', [EventApiController::class, 'update'])->whereNumber('event')->name('events.update');
        Route::post('events/{event}/publish', [EventApiController::class, 'publish'])->whereNumber('event')->name('events.publish');
        Route::post('events/{event}/close', [EventApiController::class, 'close'])->whereNumber('event')->name('events.close');
        Route::post('events/{event}/cancel', [EventApiController::class, 'cancel'])->whereNumber('event')->name('events.cancel');
    });

    // v1.1 — collaborators (ADR-011). Only events the token's owner created
    // through an integration (external_ref set); reads and writes keep their
    // own budgets, like the event endpoints.
    Route::middleware('api.token:'.ApiToken::MANAGE_COLLABORATORS)->group(function (): void {
        Route::get('events/{event}/collaborators', [CollaboratorApiController::class, 'index'])
            ->whereNumber('event')->middleware('throttle:nexo-api-read')->name('collaborators.index');
        Route::post('events/{event}/collaborators', [CollaboratorApiController::class, 'store'])
            ->whereNumber('event')->middleware('throttle:nexo-api-write')->name('collaborators.store');
        Route::delete('events/{event}/collaborators/{collaborator}', [CollaboratorApiController::class, 'destroy'])
            ->whereNumber(['event', 'collaborator'])->middleware('throttle:nexo-api-write')->name('collaborators.destroy');
    });
});
