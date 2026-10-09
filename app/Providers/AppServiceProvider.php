<?php

namespace App\Providers;

use App\Domain\Structure\AssessmentUuids;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
    }
}
