<?php

use App\Domain\Mail\MailSettings;
use App\Domain\Mail\MailTemplates;
use App\Domain\Mail\NotificationMail;
use App\Domain\Notifications\NodeAssigned;
use App\Jobs\SendNotificationDigest;
use App\Jobs\SendTemplatedMail;
use App\Models\Assessment;
use App\Models\InstanceSetting;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Email for the three in-app notifications: off by default at three levels
 * (mail on, the kind allowed, the person has not opted out), coalesced into
 * one message per ten minutes, link-only unless titles are allowed, and with a
 * signed unsubscribe.
 */

function notifyMailOn(array $kinds = ['mail_notify_mentions', 'mail_notify_assignments', 'mail_notify_summaries'], bool $titles = false): void
{
    config(['arguesecure.mail' => [
        'host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'starttls', 'username' => null,
        'password' => null, 'from_address' => 'argue@example.test', 'from_name' => null,
    ]]);
    InstanceSetting::singleton()->forceFill(array_fill_keys($kinds, true) + ['mail_notify_titles' => $titles])->save();
    InstanceSetting::singleton()->forceFill(['mail_tested_fingerprint' => MailSettings::fingerprint()])->save();
}

function mention(User $user, string $title = 'Quarterly review'): void
{
    NotificationMail::record($user, ['kind' => 'mention', 'assessment_title' => $title, 'node_title' => 'Secret node text']);
}

beforeEach(function () {
    Cache::flush();
});

test('with mail off, or a kind not allowed, nothing is gathered or queued', function () {
    Bus::fake();
    $user = User::factory()->create();

    mention($user);

    notifyMailOn(['mail_notify_assignments']);
    mention($user);

    Bus::assertNotDispatched(SendNotificationDigest::class);
});

test('a person who opted out, or an inactive one, gets nothing', function () {
    Bus::fake();
    notifyMailOn();
    $out = User::factory()->create(['mail_notifications_off' => ['mention']]);
    $inactive = User::factory()->create(['is_active' => false]);

    mention($out);
    mention($inactive);

    Bus::assertNotDispatched(SendNotificationDigest::class);
});

test('ten mentions in the window are one delayed job and one message', function () {
    Bus::fake();
    notifyMailOn();
    $user = User::factory()->create();

    foreach (range(1, 10) as $_) {
        mention($user);
    }

    Bus::assertDispatchedTimes(SendNotificationDigest::class, 1);

    (new SendNotificationDigest($user->id))->handle();

    Bus::assertDispatchedTimes(SendTemplatedMail::class, 1);
    Bus::assertDispatched(SendTemplatedMail::class, fn (SendTemplatedMail $j) => $j->kind === 'notification-digest'
        && str_contains($j->values['summary'], '10× You were mentioned in a comment.'));

    // The window is closed: the next one opens a new job.
    mention($user);
    Bus::assertDispatchedTimes(SendNotificationDigest::class, 2);
});

test('the message holds no comment or node text, and no title unless titles are allowed', function () {
    Bus::fake();
    notifyMailOn();
    $user = User::factory()->create();
    mention($user);
    (new SendNotificationDigest($user->id))->handle();

    Bus::assertDispatched(SendTemplatedMail::class, fn (SendTemplatedMail $j) => ! str_contains(json_encode($j->values), 'Quarterly')
        && ! str_contains(json_encode($j->values), 'Secret node text'));

    Bus::fake();
    Cache::flush();
    notifyMailOn(titles: true);
    mention($user);
    (new SendNotificationDigest($user->id))->handle();

    Bus::assertDispatched(SendTemplatedMail::class, fn (SendTemplatedMail $j) => str_contains($j->values['summary'], '"Quarterly review"')
        && ! str_contains(json_encode($j->values), 'Secret node text'));
});

test('an opt-out or a switch-off made while the message waited is honoured', function () {
    Bus::fake();
    notifyMailOn();
    $user = User::factory()->create();
    mention($user);

    $user->forceFill(['mail_notifications_off' => ['mention']])->save();
    (new SendNotificationDigest($user->id))->handle();

    Bus::assertNotDispatched(SendTemplatedMail::class);
});

test('a real in-app notification reaches the mail gates', function () {
    Bus::fake([SendNotificationDigest::class]);
    notifyMailOn();
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->for($assessment)->create(['title' => 'Cardholder data breach']);
    $assignee = User::factory()->create();

    $assignee->notify(new NodeAssigned($node, $assessment->owner));

    Bus::assertDispatched(SendNotificationDigest::class, fn ($j) => $j->userId === $assignee->id);
    expect($assignee->notifications()->count())->toBe(1);
});

test('the unsubscribe link is signed, shows a page, and only the button turns the kind off', function () {
    $user = User::factory()->create();
    $link = NotificationMail::unsubscribeLink($user, 'mention');
    $all = NotificationMail::unsubscribeLink($user, 'all');

    $this->get($link)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Auth/Unsubscribe'));
    expect($user->fresh()->mail_notifications_off)->toBeNull();

    $this->get(preg_replace('/signature=[^&]+/', 'signature=forged', $link))->assertForbidden();
    $this->post(preg_replace('/signature=[^&]+/', 'signature=forged', $link))->assertForbidden();

    $other = url(URL::signedRoute('notifications.unsubscribe', ['user' => 99999, 'kind' => 'mention'], null, false));
    $this->get($other)->assertOk();
    $this->post($other)->assertNotFound();

    $this->post($link)->assertRedirect(route('login'));
    expect($user->fresh()->mail_notifications_off)->toBe(['mention']);
    $this->assertDatabaseHas('security_events', ['event' => 'account.mail_unsubscribed']);

    $this->post($all)->assertRedirect(route('login'));
    expect($user->fresh()->mail_notifications_off)->toBe(['mention', 'assignment', 'summary']);

    // Changing the kind in the URL breaks the signature.
    $this->post(str_replace('/mention', '/assignment', $link))->assertForbidden();
});

test('a digest that mixes kinds offers a link that stops all of them', function () {
    Bus::fake();
    notifyMailOn();
    $user = User::factory()->create();
    mention($user);
    NotificationMail::record($user, ['kind' => 'assignment']);
    (new SendNotificationDigest($user->id))->handle();

    Bus::assertDispatched(SendTemplatedMail::class, fn (SendTemplatedMail $j) => str_ends_with(parse_url($j->values['unsubscribe_link'], PHP_URL_PATH), '/all'));
});

test('a cache failure never fails the in-app notification', function () {
    notifyMailOn();
    $user = User::factory()->create();
    Cache::shouldReceive('lock')->andThrow(new RuntimeException('redis down'));

    NotificationMail::record($user, ['kind' => 'mention']);

    expect(true)->toBeTrue();
});

test('an unknown kind in a signed link is a 404', function () {
    $user = User::factory()->create();
    // The route is signed over the path only (`signed:relative`).
    $link = NotificationMail::unsubscribeLink($user, 'nonsense');

    $this->get($link)->assertNotFound();
});

test('the message carries the unsubscribe link in the footer and as a header value', function () {
    notifyMailOn();
    $out = MailTemplates::render('notification-digest', [
        'name' => 'A', 'summary' => '- x', 'open_link' => 'https://x.test/', 'unsubscribe_link' => 'https://x.test/u?signature=s',
    ]);

    expect($out['text'])->toContain('To stop these emails: https://x.test/u?signature=s')
        ->and($out['html'])->toContain('Stop these emails')
        ->and($out['unsubscribe'])->toBe('https://x.test/u?signature=s');

    expect(MailTemplates::render('invitation', ['set_password_link' => 'https://x.test/a'])['unsubscribe'])->toBeNull();
});

test('only an admin changes which kinds are mailed, and the default is none', function () {
    $this->put('/admin/email/notifications', ['kinds' => ['mention' => true], 'titles' => false])->assertRedirect('/login');
    $this->actingAs(User::factory()->create())->put('/admin/email/notifications', ['kinds' => ['mention' => true], 'titles' => false])->assertForbidden();
    expect(InstanceSetting::singleton()->mail_notify_mentions)->toBeFalse();

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->put('/admin/email/notifications', ['kinds' => ['mention' => true, 'assignment' => false, 'summary' => false], 'titles' => true])
        ->assertRedirect(route('admin.mail.edit'));

    $row = InstanceSetting::singleton();
    expect($row->mail_notify_mentions)->toBeTrue()
        ->and($row->mail_notify_assignments)->toBeFalse()
        ->and($row->mail_notify_titles)->toBeTrue();
    $this->assertDatabaseHas('security_events', ['event' => 'mail.notifications_updated']);
});

test('the profile lists only allowed kinds, defaulting to on, and saves the person\'s choice', function () {
    notifyMailOn(['mail_notify_mentions']);
    $user = User::factory()->create();

    $this->actingAs($user)->get('/profile')->assertInertia(fn (Assert $page) => $page
        ->has('mailNotifications', 1)
        ->where('mailNotifications.0.kind', 'mention')
        ->where('mailNotifications.0.on', true));

    $this->actingAs($user)->patch('/profile/mail-notifications', ['kinds' => ['mention' => false, 'assignment' => true, 'summary' => true]])
        ->assertRedirect();
    expect($user->fresh()->mail_notifications_off)->toBe(['mention']);

    $this->actingAs($user)->get('/profile')->assertInertia(fn (Assert $page) => $page->where('mailNotifications.0.on', false));
});

test('saving the profile leaves alone the kinds the administrator has not allowed', function () {
    notifyMailOn(['mail_notify_mentions']);
    $user = User::factory()->create();

    $this->actingAs($user)->patch('/profile/mail-notifications', ['kinds' => ['mention' => true]])->assertRedirect();

    expect($user->fresh()->mail_notifications_off)->toBe([]);

    InstanceSetting::singleton()->forceFill(['mail_notify_assignments' => true])->save();
    expect(NotificationMail::wants($user->fresh(), 'assignment'))->toBeTrue();
});

test('an admin save that leaves a kind out is refused, not read as off', function () {
    $admin = User::factory()->admin()->create();
    InstanceSetting::singleton()->forceFill(['mail_notify_assignments' => true])->save();

    $this->actingAs($admin)->put('/admin/email/notifications', ['kinds' => ['mention' => true], 'titles' => false])
        ->assertSessionHasErrors(['kinds.assignment', 'kinds.summary']);

    expect(InstanceSetting::singleton()->mail_notify_assignments)->toBeTrue();
});

test('the profile shows nothing when no kind is allowed', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/profile')->assertInertia(fn (Assert $page) => $page->has('mailNotifications', 0));
});

test('an unsubscribe link still works after the hostname changed', function () {
    $user = User::factory()->create();
    $link = NotificationMail::unsubscribeLink($user, 'mention');

    $moved = preg_replace('~^https?://[^/]+~', 'https://new-host.example.test', $link);

    expect($moved)->not->toBe($link);
    $this->post($moved)->assertRedirect(route('login'));
    expect($user->fresh()->mail_notifications_off)->toBe(['mention']);
});

test('an assessment title is one short line without a link, and cannot add text of its own', function () {
    Bus::fake();
    notifyMailOn(titles: true);
    $user = User::factory()->create();
    $nasty = "Review\r\nClick https://evil.example/login now www.evil.example \"x\"".str_repeat('a', 200);

    mention($user, $nasty);
    (new SendNotificationDigest($user->id))->handle();

    Bus::assertDispatched(SendTemplatedMail::class, function (SendTemplatedMail $j) {
        $summary = $j->values['summary'];

        return substr_count($summary, "\n") === 0
            && ! str_contains($summary, 'evil')
            && ! str_contains($summary, 'https')
            && str_contains($summary, '[link removed]')
            && mb_strlen($summary) < 200;
    });
});

test('an address nobody confirmed gets no mail', function () {
    Bus::fake();
    notifyMailOn();
    $unverified = User::factory()->unverified()->create();

    mention($unverified);

    Bus::assertNotDispatched(SendNotificationDigest::class);
});

test('the digest is delayed by the window, not sent at once', function () {
    Bus::fake();
    notifyMailOn();
    $user = User::factory()->create();

    mention($user);

    Bus::assertDispatched(SendNotificationDigest::class, function (SendNotificationDigest $job) {
        return $job->delay !== null && now()->diffInMinutes($job->delay, false) >= NotificationMail::WINDOW_MINUTES - 1
            && now()->diffInMinutes($job->delay, false) <= NotificationMail::WINDOW_MINUTES;
    });
});

test('the assignment and summary paths are mailed with their own sentences', function () {
    Bus::fake();
    notifyMailOn();
    $user = User::factory()->create();

    NotificationMail::record($user, ['kind' => 'assignment', 'assessment_title' => 'A']);
    NotificationMail::record($user, ['kind' => 'summary', 'succeeded' => true]);
    NotificationMail::record($user, ['kind' => 'summary', 'succeeded' => false]);
    (new SendNotificationDigest($user->id))->handle();

    Bus::assertDispatched(SendTemplatedMail::class, fn (SendTemplatedMail $j) => str_contains($j->values['summary'], 'A node was assigned to you.')
        && str_contains($j->values['summary'], 'Your summary draft is ready.')
        && str_contains($j->values['summary'], 'Your summary draft could not be made.'));
});

test('a flood keeps at most the cap, and a lost job does not block the next window', function () {
    Bus::fake();
    notifyMailOn();
    $user = User::factory()->create();

    foreach (range(1, NotificationMail::MAX_PENDING + 20) as $_) {
        mention($user);
    }

    expect(NotificationMail::take($user->id))->toHaveCount(NotificationMail::MAX_PENDING);

    // take() closes the window; had the job been lost, the flag would expire
    // after WINDOW_MINUTES + 5 and the next notification opens a new one.
    Cache::forget('mail-notify-queued:'.$user->id);
    mention($user);
    Bus::assertDispatchedTimes(SendNotificationDigest::class, 2);
});
