<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\Auth\OidcController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Auth\SetupController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Auth\UnsubscribeController;
use Illuminate\Support\Facades\Route;

// First-run setup (security.md §4). Guarded twice over: the
// 'guest' middleware here, and SetupController/SetupRequest re-checking
// that no user exists at all — the middleware alone would not survive
// someone hitting the route directly with no session. Rate-limited per
// security.md §9 ("First-run setup: 5/min per IP").
Route::middleware(['guest', 'throttle:setup'])->group(function () {
    Route::get('setup', [SetupController::class, 'create'])->name('setup');
    Route::post('setup', [SetupController::class, 'store'])->name('setup.store');
});

// Single sign-on (security.md §4). Neither 'guest' nor 'auth': the callback must work
// for someone who is signed out, and the same redirect serves a signed-in
// user stepping up before an email change. Both answer 404 unless a
// provider is configured, and both are GETs (the provider redirects back with
// a plain GET).
Route::middleware('throttle:oidc')->prefix('auth/oidc')->group(function () {
    Route::get('redirect', [OidcController::class, 'redirect'])->name('oidc.redirect');
    Route::get('callback', [OidcController::class, 'callback'])->name('oidc.callback');
});

// Emailed one-time links. Neither 'guest' nor 'auth': the person may be signed
// out, or signed in as someone else. The GET only shows a page with a button,
// because mail scanners fetch links and a fetch must not spend the token.
Route::middleware('throttle:mail-link')->group(function () {
    Route::get('invitation/{token}', [InvitationController::class, 'show'])->name('invitation.show');
    Route::post('invitation/{token}', [InvitationController::class, 'store'])->name('invitation.store');
    // Signed (APP_KEY) and not expiring: it must work from an old email.
    Route::get('notifications/unsubscribe/{user}/{kind}', [UnsubscribeController::class, 'show'])->middleware('signed:relative')->name('notifications.unsubscribe');
    Route::post('notifications/unsubscribe/{user}/{kind}', [UnsubscribeController::class, 'store'])->middleware('signed:relative')->name('notifications.unsubscribe.store');
    Route::get('reset-password/{token}', [ResetPasswordController::class, 'show'])->name('password.reset');
    Route::post('reset-password/{token}', [ResetPasswordController::class, 'store'])->name('password.reset.store');
    Route::get('email/verify/{token}', [EmailVerificationController::class, 'show'])->name('verification.show');
    Route::post('email/verify/{token}', [EmailVerificationController::class, 'store'])->name('verification.store');
});

Route::middleware(['guest', 'throttle:forgot-password'])->group(function () {
    Route::get('forgot-password', [ForgotPasswordController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [ForgotPasswordController::class, 'store'])->name('password.email');
});

// Self-registration: 404 unless an admin has switched it on and mail works.
Route::middleware('guest')->group(function () {
    Route::get('register', [RegisterController::class, 'create'])->name('register');
    Route::post('register', [RegisterController::class, 'store'])->middleware('throttle:register')->name('register.store');
});

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    // The second step of two-factor sign-in (security.md §4). A guest route on purpose — the account is not
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
