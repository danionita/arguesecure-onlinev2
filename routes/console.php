<?php

use App\Domain\Assistant\Availability;
use App\Domain\Assistant\Help\CorpusWarmer;
use App\Domain\Snapshots\AutoVersioner;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * 2.5.1: versions are saved automatically — after a pause in editing, or after
 * a run of changes — so the History page always has something to go back to.
 * The rules live in AutoVersioner; the `scheduler` container runs this every
 * five minutes (docker-compose.yml, `schedule:work`).
 */
Artisan::command('arguesecure:save-versions', function (AutoVersioner $versioner) {
    $saved = $versioner->run();
    $this->info("Saved {$saved} version(s).");
})->purpose('Save a version of every assessment with unsaved changes that are due one');

Schedule::command('arguesecure:save-versions')
    ->everyFiveMinutes()
    ->withoutOverlapping();

/*
 * 2.6 (PLAN-2.6.md D57): after the assistant's runtime starts, read the help
 * corpus into its cache before anyone asks, so the first question after a
 * restart is not the minute-and-a-half one. CorpusWarmer does nothing unless
 * the help feature is available and the runtime reports a slot that has never
 * served a request, so this runs once per runtime start however often it is
 * scheduled.
 *
 * In the background, because a warm-up takes about as long as that first
 * question would have and must not hold up the version saver above.
 */
Artisan::command('assistant:warm', function (CorpusWarmer $warmer) {
    $this->info('Help corpus: '.$warmer->warmIfFresh().'.');
})->purpose('Read the help pages into a freshly started assistant runtime, once');

// The overlap lock expires after ten minutes, not Laravel's default day: a
// warm-up killed mid-run — the scheduler restarted alongside the runtime, as
// `docker compose up -d` does — never releases it, and a day-long lock would
// silently skip the warm-up after every restart until tomorrow. Ten minutes
// is several warm-ups' worth on the large model.
Schedule::command('assistant:warm')
    ->everyMinute()
    ->when(fn (): bool => Availability::configured())
    ->withoutOverlapping(10)
    ->runInBackground();
