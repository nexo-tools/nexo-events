<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\CheckinController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\PublicEventController;
use App\Http\Controllers\TicketController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

// Public help center. Registered before the prefixed public surfaces below; the
// app uses no bare {slug} catch-all, so 'help' is safe as a top-level path.
Route::get('/help', HelpController::class)->name('help');

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('register', [RegisteredUserController::class, 'store'])->middleware('throttle:10,1');

    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:20,1');

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:5,1')->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:5,1')->name('password.store');
});

// Organizer area.
Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    // Email verification. Deliberately NOT applied as `verified` middleware on
    // the organizer area: it gates publishing only (ADR-007 §1), so drafts stay
    // reachable while the organizer waits for the mail.
    Route::get('verify-email', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('verify-email/send', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:6,1')->name('verification.send');

    Route::prefix('app')->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');

        Route::get('eventos/nuevo', [EventController::class, 'create'])->name('events.create');
        Route::post('eventos', [EventController::class, 'store'])->name('events.store');
        Route::get('eventos/{event}/editar', [EventController::class, 'edit'])->name('events.edit');
        Route::put('eventos/{event}', [EventController::class, 'update'])->name('events.update');
        Route::post('eventos/{event}/publicar', [EventController::class, 'publish'])->name('events.publish');
        Route::post('eventos/{event}/cerrar', [EventController::class, 'close'])->name('events.close');
        Route::post('eventos/{event}/cancelar', [EventController::class, 'cancel'])->name('events.cancel');
        Route::get('eventos/{event}/registrados', [EventController::class, 'registrations'])->name('events.registrations');

        Route::get('eventos/{event}/escanear', [CheckinController::class, 'scanner'])->name('events.scan');
        // Generous on purpose: a busy door legitimately scans once every couple
        // of seconds, and a limit that fires mid-event is worse than the abuse
        // it prevents. Authenticated requests throttle per user, so one
        // organizer's rush never affects another's door (ADR-007 §2).
        Route::post('eventos/{event}/checkin', [CheckinController::class, 'checkin'])
            ->middleware('throttle:120,1')->name('events.checkin');
        Route::post('tickets/{ticket}/checkin', [CheckinController::class, 'manual'])
            ->middleware('throttle:60,1')->name('tickets.checkin');
    });
});

// Nexo ID SSO client (no-op unless NEXO_SSO_ENABLED).
require __DIR__.'/nexo-sso.php';

// Public surfaces (prefixed, so no bare slug catch-all is needed).
Route::get('e/{event:slug}', [PublicEventController::class, 'show'])->name('public.event');
Route::post('e/{event:slug}/registro', [PublicEventController::class, 'register'])
    ->middleware('throttle:10,1')->name('public.register');
// Tighter than registration: each call rotates a ticket token (ADR-008), so it
// is the one public write that can degrade something an attendee already holds.
Route::post('e/{event:slug}/reenviar', [PublicEventController::class, 'resend'])
    ->middleware('throttle:3,10')->name('public.resend');
// Throttled per IP, but loosely: attendees at a venue all share one NAT address,
// so a tight limit would lock a queue out of their own tickets. Guessing a token
// is not the threat this defends against (40 random chars) — absorbing scan-spam is.
Route::get('t/{token}', [TicketController::class, 'show'])
    ->middleware('throttle:60,1')->name('ticket.show');
