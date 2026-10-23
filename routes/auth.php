<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\OidcController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\SetupController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use Illuminate\Support\Facades\Route;

// First-run setup (PLAN.md Phase 3 item 2). Guarded twice over: the
// 'guest' middleware here, and SetupController/SetupRequest re-checking
// that no user exists at all — the middleware alone would not survive
// someone hitting the route directly with no session. Rate-limited per
// security.md §9 ("First-run setup: 5/min per IP").
Route::middleware(['guest', 'throttle:setup'])->group(function () {
    Route::get('setup', [SetupController::class, 'create'])->name('setup');
    Route::post('setup', [SetupController::class, 'store'])->name('setup.store');
});

// W117: single sign-on. Neither 'guest' nor 'auth': the callback must work
// for someone who is signed out, and the same redirect serves a signed-in
// user stepping up before an email change (D79). Both answer 404 unless a
// provider is configured, and both are GETs (D74).
Route::middleware('throttle:oidc')->prefix('auth/oidc')->group(function () {
    Route::get('redirect', [OidcController::class, 'redirect'])->name('oidc.redirect');
    Route::get('callback', [OidcController::class, 'callback'])->name('oidc.callback');
});

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    // W116: the second step. A guest route on purpose — the account is not
    // signed in until the code is right. Its limits live in the controller
    // (5/min per pending account and per IP, failures only), as login's do.
    Route::get('two-factor-challenge', [TwoFactorChallengeController::class, 'create'])
        ->name('two-factor.challenge');
    Route::post('two-factor-challenge', [TwoFactorChallengeController::class, 'store'])
        ->middleware('throttle:two-factor')
        ->name('two-factor.challenge.store');
});

// 'active' is deliberately on this whole authenticated group, not just
// the admin/profile areas — security.md §4 requires is_active enforced on
// every request, and password confirmation/change/logout are exactly the
// kind of route a deactivated session could otherwise still reach.
Route::middleware(['auth', 'active', 'throttle:authenticated'])->group(function () {
    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    // security.md §9 rates "password change" at 5/min per user, and
    // confirm-password is the same surface wearing a different name: it
    // takes the account's current password and answers whether it was
    // right. Left on the 300/min default it would be the one unthrottled
    // online password oracle left in the route table, reachable with a
    // stolen session cookie and no password.
    //
    // The limiter is keyed per authenticated user rather than per IP, so
    // one account's lockout cannot be induced by an unrelated address.
    Route::middleware('throttle:credentials')->group(function () {
        Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

        Route::put('password', [PasswordController::class, 'update'])->name('password.update');
    });

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
