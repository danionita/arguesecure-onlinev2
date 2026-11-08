<?php

use App\Domain\Accounts\SelfRegistration;
use App\Domain\Assistant\Availability;
use App\Domain\Assistant\Help\CorpusWarmer;
use App\Domain\Mail\Invitations;
use App\Domain\Retention\TrashPurger;
use App\Domain\Snapshots\AutoVersioner;
use App\Models\SecurityEventRecord;
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
 * 2.6: after the assistant's runtime starts, read the help
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

/*
 * Trash (security.md §12): erase every assessment deleted longer ago than
 * TRASH_RETENTION_DAYS. Daily is enough — the period is counted in days —
 * and the `scheduler` container runs it like the version saver above.
 */
Artisan::command('arguesecure:purge-deleted', function (TrashPurger $purger) {
    $erased = $purger->purge();
    $this->info("Erased {$erased} deleted assessment(s).");
})->purpose('Erase assessments deleted longer ago than the retention period');

/*
 * Invitation reminders: an invited account nobody has signed in to gets a new
 * link after 3 days and again after 7, then no more. Does nothing with mail
 * off. Daily is enough; the periods are counted in days.
 */
Artisan::command('arguesecure:send-reminders', function () {
    $sent = Invitations::sendDueReminders();
    $this->info("Sent {$sent} reminder(s).");
})->purpose('Remind invited accounts that have never been used');

Schedule::command('arguesecure:send-reminders')
    ->dailyAt('08:00')
    ->withoutOverlapping();

Schedule::command('arguesecure:purge-deleted')
    ->daily()
    ->withoutOverlapping();

/*
 * Security event log (security.md §11.1): remove stored security events older than
 * SECURITY_LOG_RETENTION_DAYS. The stderr stream is not touched.
 */
Artisan::command('arguesecure:purge-security-events', function () {
    $removed = SecurityEventRecord::query()
        ->where('created_at', '<', now()->subDays((int) config('arguesecure.security_log_retention_days')))
        ->delete();
    $this->info("Removed {$removed} security event(s).");
})->purpose('Remove stored security events older than the retention period');

Schedule::command('arguesecure:purge-security-events')
    ->daily()
    ->withoutOverlapping();

/* Self-registrations whose link has expired can no longer be confirmed. */
Artisan::command('arguesecure:prune-pending-registrations', function () {
    $this->info('Removed '.SelfRegistration::prune().' pending registration(s).');
})->purpose('Remove pending self-registrations older than the link lifetime');

Schedule::command('arguesecure:prune-pending-registrations')
    ->daily()
    ->withoutOverlapping();
