<?php

namespace App\Providers;

use App\Domain\Accounts\PasswordPolicy;
use App\Domain\Accounts\SelfRegistration;
use App\Domain\Audit\SecurityEventSubscriber;
use App\Domain\Structure\AssessmentUuids;
use App\Events\EmailAddressVerified;
use App\Listeners\MailInAppNotification;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\NotPwnedVerifier;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // `scoped`, not `singleton`: the memo must not outlive the request (or
        // the test) that filled it. See AssessmentUuids' own docblock for what
        // a process-lifetime memo would get wrong, and why it would stay green
        // while getting it wrong.
        $this->app->scoped(AssessmentUuids::class);

        // Laravel's verifier waits up to 30 seconds for api.pwnedpasswords.com.
        // The lookup already fails open (an exception or a non-2xx answer counts
        // as "not found"), but only after the wait, so a slow service stalled
        // first-run setup and every password change: security.md §4 says the
        // check never blocks anyone. `extend` wraps the framework's
        // deferred binding whenever it loads, so provider order cannot undo it.
        $this->app->extend(
            UncompromisedVerifier::class,
            fn ($verifier, $app) => new NotPwnedVerifier($app->make(HttpFactory::class), PasswordPolicy::BREACH_CHECK_TIMEOUT),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // The security event log (security.md §11). Event discovery
        // scans app/Listeners only, so a subscriber in app/Domain is named.
        Event::subscribe(SecurityEventSubscriber::class);

        // An empty websocket origin list rejects every browser connection and
        // the page still works, so live updates would just stop. Say why when
        // the server starts, where the operator is looking.
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            if ($event->command === 'reverb:start' && config('reverb.apps.apps.0.allowed_origins') === []) {
                Log::warning('Reverb will refuse every browser connection: APP_URL has no usable host and REVERB_ALLOWED_ORIGINS is empty. Set APP_URL to the public address, or list the hosts in REVERB_ALLOWED_ORIGINS.');
            }
        });

        // Offer every in-app notification to the mail channel's gates (off by
        // default; see App\Domain\Mail\NotificationMail).
        Event::listen(NotificationSent::class, MailInAppNotification::class);
        Event::listen(EmailAddressVerified::class, fn (EmailAddressVerified $event) => SelfRegistration::complete($event->email, $event->reference));
    }
}
