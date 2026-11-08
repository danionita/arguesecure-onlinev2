<?php

namespace App\Domain\Mail;

use App\Jobs\SendNotificationDigest;
use App\Models\InstanceSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;

/**
 * Email for the three in-app notifications that exist: a mention, an
 * assignment and a drafted summary. A delivery channel, not a new kind of
 * notification.
 *
 * Three gates, all closed by default: mail is on, an administrator allows the
 * kind, and the person has not turned it off (they have it on until they do).
 * Nothing is sent as it happens. The first notification for a person starts a
 * ten-minute window; everything that arrives in it goes out as one message, so
 * a busy thread is one email, not fifty. The message holds a sentence per
 * event and a link, never comment or node text; assessment titles appear only
 * if the administrator allows them.
 */
final class NotificationMail
{
    public const WINDOW_MINUTES = 10;

    /** Items kept per person per window; the rest are counted nowhere and dropped. */
    public const MAX_PENDING = 50;

    /** Longest title shown in a message. */
    public const TITLE_LENGTH = 80;

    /** kind => instance column, label for the switches. */
    public const KINDS = [
        'mention' => ['column' => 'mail_notify_mentions', 'label' => 'Someone mentions you in a comment'],
        'assignment' => ['column' => 'mail_notify_assignments', 'label' => 'A node is assigned to you'],
        'summary' => ['column' => 'mail_notify_summaries', 'label' => 'A summary draft you asked for is ready'],
    ];

    /** Whether the instance allows this kind to be mailed at all. */
    public static function kindAllowed(string $kind): bool
    {
        return MailSettings::enabled()
            && isset(self::KINDS[$kind])
            && (bool) InstanceSetting::singleton()->{self::KINDS[$kind]['column']};
    }

    public static function titlesAllowed(): bool
    {
        return (bool) InstanceSetting::singleton()->mail_notify_titles;
    }

    public static function wants(User $user, string $kind): bool
    {
        // An address nobody has confirmed (a changed one, a temporary-password
        // account) gets no mail: the notification could reach a stranger.
        return $user->is_active && $user->email_verified_at !== null && ! in_array($kind, $user->mail_notifications_off ?? [], true);
    }

    /**
     * Called for every in-app notification (NotificationSent listener).
     *
     * @param  array<string, mixed>  $data  the notification's own payload
     */
    public static function record(User $user, array $data): void
    {
        // Email is an extra channel: a cache or lock failure must never fail
        // the in-app notification, or the comment or assignment behind it.
        try {
            self::gather($user, $data);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @param  array<string, mixed>  $data */
    private static function gather(User $user, array $data): void
    {
        $kind = (string) ($data['kind'] ?? '');

        if (! self::kindAllowed($kind) || ! self::wants($user, $kind)) {
            return;
        }

        Cache::lock("mail-notify:{$user->id}", 5)->block(3, function () use ($user, $data, $kind): void {
            $pending = Cache::get("mail-notify-items:{$user->id}", []);
            // A flood is already one message; past the cap more items add nothing.
            if (count($pending) >= self::MAX_PENDING) {
                return;
            }
            $pending[] = ['kind' => $kind, 'data' => array_intersect_key($data, array_flip(['assessment_title', 'node_title', 'succeeded']))];
            Cache::put("mail-notify-items:{$user->id}", $pending, now()->addHours(2));

            // The first one opens the window; the rest ride in it.
            if (Cache::add("mail-notify-queued:{$user->id}", true, now()->addMinutes(self::WINDOW_MINUTES + 5))) {
                SendNotificationDigest::dispatch($user->id)->delay(now()->addMinutes(self::WINDOW_MINUTES));
            }
        });
    }

    /**
     * Takes what has gathered for a person and closes the window.
     *
     * @return list<array{kind: string, data: array<string, mixed>}>
     */
    public static function take(int $userId): array
    {
        return Cache::lock("mail-notify:{$userId}", 5)->block(3, function () use ($userId): array {
            $items = Cache::pull("mail-notify-items:{$userId}", []);
            Cache::forget("mail-notify-queued:{$userId}");

            return $items;
        });
    }

    /**
     * One line per kind of event, with a count, from what gathered. Titles
     * only when the administrator allows them.
     *
     * @param  list<array{kind: string, data: array<string, mixed>}>  $items
     */
    public static function summarise(array $items, bool $titles): string
    {
        $lines = [];

        foreach ($items as $item) {
            $data = $item['data'];
            $title = $titles ? self::cleanTitle($data['assessment_title'] ?? null) : '';
            $of = $title !== '' ? ' in "'.$title.'"' : '';

            $line = match ($item['kind']) {
                'mention' => 'You were mentioned in a comment'.$of.'.',
                'assignment' => 'A node was assigned to you'.$of.'.',
                'summary' => (($data['succeeded'] ?? true) ? 'Your summary draft is ready' : 'Your summary draft could not be made').$of.'.',
                default => null,
            };

            if ($line !== null) {
                $lines[$line] = ($lines[$line] ?? 0) + 1;
            }
        }

        return implode("\n", array_map(
            fn (string $line, int $count) => '- '.($count > 1 ? "{$count}× " : '').$line,
            array_keys($lines),
            array_values($lines),
        ));
    }

    /**
     * A title is text anyone with write access chose, going into someone's
     * mailbox: one line, no control characters, no link, bounded.
     */
    public static function cleanTitle(mixed $title): string
    {
        if (! is_string($title)) {
            return '';
        }

        $title = preg_replace('/[\p{C}\s]+/u', ' ', $title) ?? '';
        $title = preg_replace('~[a-z][a-z0-9+.-]*://\S*|\bwww\.\S*~iu', '[link removed]', $title) ?? '';
        $title = str_replace('"', "'", trim($title));

        return mb_strlen($title) > self::TITLE_LENGTH ? mb_substr($title, 0, self::TITLE_LENGTH - 1).'…' : $title;
    }

    /**
     * The signed, non-expiring link that turns one kind off for one person.
     * The signature covers the path only, so it survives a change of hostname
     * or scheme between sending the message and following it.
     */
    public static function unsubscribeLink(User $user, string $kind): string
    {
        return url(URL::signedRoute('notifications.unsubscribe', ['user' => $user->id, 'kind' => $kind], null, false));
    }

    /** `all` turns every kind off: what a digest's single link means. */
    public static function turnOff(User $user, string $kind): void
    {
        $kinds = $kind === 'all' ? array_keys(self::KINDS) : [$kind];
        /** @var list<string> $current */
        $current = $user->mail_notifications_off ?? [];
        $off = array_values(array_unique([...$current, ...$kinds]));
        $user->forceFill(['mail_notifications_off' => $off])->save();
    }
}
