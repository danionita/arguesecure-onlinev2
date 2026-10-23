<?php

/**
 * W128 (D96–D100) — the stored security event log and its admin download.
 */

use App\Domain\Audit\SecurityEvent;
use App\Domain\Audit\SecurityLog;
use App\Models\SecurityEventRecord;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

require_once __DIR__.'/../Audit/Capture.php';

/**
 * Creating an account (a factory user included) writes `account.created`, and
 * every download writes `admin.log_downloaded`, so a test that counts rows
 * starts from an empty table after its users exist.
 */
function resetLog(): void
{
    SecurityEventRecord::query()->delete();
}

/** Inserts a row directly, at a chosen time. */
function storedEvent(string $event, string $at, array $attrs = []): SecurityEventRecord
{
    $row = new SecurityEventRecord;
    $row->created_at = $at;
    $row->level = 'info';
    $row->event = $event;
    $row->actor_email = $attrs['actor_email'] ?? null;
    $row->target = $attrs['target'] ?? null;
    $row->ip = '127.0.0.1';
    $row->details = $attrs['details'] ?? [];
    $row->save();

    return $row;
}

test('a guest is sent to sign-in and a non-admin is refused, on the page and the download', function () {
    $this->get('/admin/security-log')->assertRedirect('/login');
    $this->get('/admin/security-log/download?format=csv')->assertRedirect('/login');

    $user = User::factory()->create();
    $this->actingAs($user)->get('/admin/security-log')->assertForbidden();
    $this->actingAs($user)->get('/admin/security-log/download?format=csv')->assertForbidden();
});

test('a refused non-admin is itself logged as authz.denied', function () {
    $user = User::factory()->create();
    $log = captureSecurityLog();

    $this->actingAs($user)->get('/admin/security-log/download?format=csv')->assertForbidden();

    expect(securityLine($log, 'authz.denied')['details']['route'])->toBe('admin.security-log.download');
});

test('every security channel record is also stored, with the same fields', function () {
    $admin = User::factory()->admin()->create();

    SecurityLog::record(SecurityEvent::SettingsUpdated, $admin, null, ['settings' => ['x' => 1]]);

    $row = SecurityEventRecord::query()->where('event', 'admin.settings_updated')->firstOrFail();
    expect($row->actor_id)->toBe($admin->id)
        ->and($row->actor_email)->toBe($admin->email)
        ->and($row->level)->toBe('info')
        ->and($row->details)->toBe(['settings' => ['x' => 1]])
        ->and($row->ip)->toBe('127.0.0.1');
});

test('a secret passed in details is redacted in the stored row', function () {
    SecurityLog::record(SecurityEvent::SettingsUpdated, null, null, [
        'password' => 'hunter2-hunter2',
        'link' => 'https://x.test/s/'.str_repeat('a', 43),
    ]);

    $row = SecurityEventRecord::query()->where('event', 'admin.settings_updated')->firstOrFail();
    expect($row->details['password'])->toBe('[redacted]')
        ->and($row->details['link'])->toBe('https://x.test/s/[redacted]');
});

test('a failing insert does not break the request that caused the event', function () {
    SecurityEventRecord::creating(fn () => throw new RuntimeException('database unavailable'));
    $user = User::factory()->create();

    // authz.denied is recorded for this 403 and its insert fails.
    $this->actingAs($user)->get('/admin/security-log')->assertForbidden();
});

test('csv and json downloads carry the rows, and format is required', function () {
    $admin = User::factory()->admin()->create();
    resetLog();
    storedEvent('auth.login', '2026-03-01 10:00:00', ['actor_email' => 'a@example.com', 'target' => ['type' => 'user', 'id' => 1, 'email' => 'a@example.com']]);

    $csv = $this->actingAs($admin)->get('/admin/security-log/download?format=csv');
    $csv->assertOk()->assertHeader('X-Rows-Truncated', '0');
    expect($csv->headers->get('Content-Type'))->toStartWith('text/csv');
    $lines = array_values(array_filter(explode("\n", $csv->streamedContent())));
    expect($lines[0])->toBe('id,time,level,event,actor_id,actor_email,target_type,target_id,target_email,ip,details')
        ->and($lines[1])->toContain('2026-03-01T10:00:00Z', 'auth.login', 'a@example.com');

    $json = $this->actingAs($admin)->get('/admin/security-log/download?format=json&event=auth.login');
    $body = json_decode($json->streamedContent(), true, flags: JSON_THROW_ON_ERROR);
    expect($body['truncated'])->toBeFalse()
        ->and($body['events'])->toHaveCount(1)
        ->and($body['events'][0]['event'])->toBe('auth.login')
        ->and($body['events'][0]['actor'])->toBe(['id' => null, 'email' => 'a@example.com']);

    $this->actingAs($admin)->get('/admin/security-log/download')->assertSessionHasErrors('format');
    $this->actingAs($admin)->get('/admin/security-log/download?format=xml')->assertSessionHasErrors('format');
});

test('date range is inclusive UTC days and event filters by name or family', function () {
    $admin = User::factory()->admin()->create();
    resetLog();
    storedEvent('auth.login', '2026-03-01 23:59:59');
    storedEvent('auth.logout', '2026-03-02 00:00:00');
    storedEvent('account.created', '2026-03-02 23:59:59');
    storedEvent('auth.login', '2026-03-03 00:00:00');

    $events = function (string $qs) use ($admin): array {
        $res = $this->actingAs($admin)->get('/admin/security-log/download?format=json&'.$qs);
        $res->assertOk();

        $names = array_column(json_decode($res->streamedContent(), true)['events'], 'event');

        // Earlier downloads in this test are events too.
        return array_values(array_filter($names, fn (string $name) => $name !== 'admin.log_downloaded'));
    };

    expect($events('from=2026-03-02&to=2026-03-02'))->toBe(['auth.logout', 'account.created'])
        ->and($events('from=2026-03-02'))->toBe(['auth.logout', 'account.created', 'auth.login'])
        ->and($events('to=2026-03-01'))->toBe(['auth.login'])
        ->and($events('event=auth'))->toBe(['auth.login', 'auth.logout', 'auth.login'])
        ->and($events('event=auth.logout'))->toBe(['auth.logout']);
});

test('bad filters are refused with 422-style validation errors', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/admin/security-log/download?format=csv&from=2026-03-05&to=2026-03-01')->assertSessionHasErrors('to');
    $this->actingAs($admin)->get('/admin/security-log/download?format=csv&event=users;drop')->assertSessionHasErrors('event');
    $this->actingAs($admin)->get('/admin/security-log/download?format=csv&event=nope.nope')->assertSessionHasErrors('event');
    $this->actingAs($admin)->get('/admin/security-log/download?format=csv&from=yesterday')->assertSessionHasErrors('from');
});

test('a download is capped and says so', function () {
    $admin = User::factory()->admin()->create();
    resetLog();
    $rows = [];
    for ($i = 0; $i < 50_001; $i++) {
        $rows[] = ['created_at' => '2026-03-01 10:00:00', 'level' => 'info', 'event' => 'auth.login'];
    }
    foreach (array_chunk($rows, 5000) as $chunk) {
        SecurityEventRecord::query()->toBase()->insert($chunk);
    }

    $json = $this->actingAs($admin)->get('/admin/security-log/download?format=json');
    $json->assertHeader('X-Rows-Truncated', '1');
    $body = json_decode($json->streamedContent(), true);
    expect($body['truncated'])->toBeTrue()->and($body['events'])->toHaveCount(50_000);
});

test('csv cells that a spreadsheet would run as a formula are prefixed', function () {
    $admin = User::factory()->admin()->create();
    resetLog();
    foreach (['=HYPERLINK("http://x","y")', '+1', '-1', '@a', "\tx", "\rx"] as $i => $typed) {
        storedEvent('auth.login_failed', '2026-03-01 10:00:0'.$i, ['target' => ['type' => 'user', 'id' => null, 'email' => $typed]]);
    }

    $csv = $this->actingAs($admin)->get('/admin/security-log/download?format=csv')->streamedContent();
    $stream = fopen('php://memory', 'w+');
    fwrite($stream, $csv);
    rewind($stream);
    fgetcsv($stream, null, ',', '"', '\\'); // header
    $targets = [];
    while (($cells = fgetcsv($stream, null, ',', '"', '\\')) !== false) {
        $targets[] = $cells[8];
    }
    fclose($stream);

    expect($targets)->toBe(["'=HYPERLINK(\"http://x\",\"y\")", "'+1", "'-1", "'@a", "'\tx", "'\rx"]);

    $json = json_decode($this->actingAs($admin)->get('/admin/security-log/download?format=json&event=auth.login_failed')->streamedContent(), true);
    expect($json['events'][0]['target']['email'])->toBe('=HYPERLINK("http://x","y")');
});

test('the seventh download inside a minute is throttled, per admin', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->admin()->create();

    for ($i = 0; $i < 6; $i++) {
        $this->actingAs($admin)->get('/admin/security-log/download?format=csv')->assertOk();
    }
    $this->actingAs($admin)->get('/admin/security-log/download?format=csv')->assertStatus(429);
    $this->actingAs($other)->get('/admin/security-log/download?format=csv')->assertOk();
});

test('a download is logged before it streams, with its filters and count', function () {
    $admin = User::factory()->admin()->create();
    resetLog();
    storedEvent('auth.login', '2026-03-01 10:00:00');
    $log = captureSecurityLog();

    // Not reading the body: the line must already exist.
    $this->actingAs($admin)->get('/admin/security-log/download?format=json&from=2026-03-01&event=auth');

    $line = securityLine($log, 'admin.log_downloaded');
    expect($line['actor']['id'])->toBe($admin->id)
        ->and($line['details'])->toBe([
            'format' => 'json', 'from' => '2026-03-01', 'to' => null, 'event' => 'auth', 'rows' => 1, 'truncated' => false,
        ]);
    expect(SecurityEventRecord::query()->where('event', 'admin.log_downloaded')->count())->toBe(1);
});

test('the page shows the latest 50 matching rows newest first, and the retention', function () {
    $admin = User::factory()->admin()->create();
    resetLog();
    for ($i = 0; $i < 60; $i++) {
        storedEvent('auth.login', '2026-03-01 10:00:00');
    }

    $this->actingAs($admin)->get('/admin/security-log')->assertInertia(fn ($page) => $page
        ->component('Admin/SecurityLog')
        ->has('rows', 50)
        ->where('retentionDays', 90)
        ->where('maxRows', 50000));
});

test('the purge removes rows older than the retention and no others', function () {
    resetLog();
    config(['arguesecure.security_log_retention_days' => 10]);
    $old = storedEvent('auth.login', now()->subDays(11)->format('Y-m-d H:i:s'));
    $kept = storedEvent('auth.login', now()->subDays(9)->format('Y-m-d H:i:s'));

    Artisan::call('arguesecure:purge-security-events');

    expect(SecurityEventRecord::query()->pluck('id')->all())->toBe([$kept->id])
        ->and(SecurityEventRecord::query()->whereKey($old->id)->exists())->toBeFalse();
});

test('the retention setting falls back to 90 and never goes below 1', function () {
    $read = function (string $value): int {
        putenv('SECURITY_LOG_RETENTION_DAYS='.$value);
        $_ENV['SECURITY_LOG_RETENTION_DAYS'] = $value;
        $config = require base_path('config/arguesecure.php');

        return $config['security_log_retention_days'];
    };

    expect($read('0'))->toBe(1)
        ->and($read('abc'))->toBe(90)
        ->and($read('30'))->toBe(30);

    putenv('SECURITY_LOG_RETENTION_DAYS');
    unset($_ENV['SECURITY_LOG_RETENTION_DAYS']);
});

test('the purge is scheduled daily', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains($e->command, 'arguesecure:purge-security-events'));

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('0 0 * * *');
});
