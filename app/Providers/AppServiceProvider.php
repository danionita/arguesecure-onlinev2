<?php

namespace App\Providers;

use App\Domain\Accounts\PasswordPolicy;
use App\Domain\Audit\SecurityEventSubscriber;
use App\Domain\Structure\AssessmentUuids;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Event;
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
        // check never blocks anyone (D84). `extend` wraps the framework's
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

        // W111: the security event log (security.md §11). Event discovery
        // scans app/Listeners only, so a subscriber in app/Domain is named.
        Event::subscribe(SecurityEventSubscriber::class);
    }
}
