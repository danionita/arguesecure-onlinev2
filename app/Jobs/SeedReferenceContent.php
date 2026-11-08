<?php

namespace App\Jobs;

use App\Models\User;
use Database\Seeders\SampleContentSeeder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The bundled templates and catalogue libraries, seeded for the first
 * administrator once they exist.
 *
 * This used to run inside the first-run setup request. Seeding is about 900
 * nodes of Eloquent writes: 6.75 s against a local MariaDB, and far more on a
 * database on a slow disk (a Windows bind mount, an Unraid array), so the
 * person who had just pressed "Create administrator" watched the setup page
 * for as long as the seeding took, and the end-to-end suite's 10-second
 * wait for the redirect timed out on every run. Slow work is a queued job
 * here; the seeders are idempotent, so a retry cannot duplicate anything.
 *
 * A failure is logged and swallowed, as it always was: the account is
 * already committed and the instance works without the templates.
 */
class SeedReferenceContent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $ownerId) {}

    public function handle(): void
    {
        $owner = User::query()->find($this->ownerId);

        if ($owner === null) {
            return;
        }

        try {
            (new SampleContentSeeder)->seedReferenceContent($owner);
        } catch (Throwable $e) {
            Log::warning('First-run template seeding failed; the instance is usable without it.', [
                'exception' => $e,
            ]);
        }
    }
}
