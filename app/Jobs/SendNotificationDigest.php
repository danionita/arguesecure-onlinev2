<?php

namespace App\Jobs;

use App\Domain\Mail\MailSettings;
use App\Domain\Mail\NotificationMail;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Closes a person's ten-minute window and mails what gathered in it as one
 * message. Re-checks every gate, since settings and preferences may have
 * changed while it waited.
 */
class SendNotificationDigest implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $userId) {}

    public function handle(): void
    {
        $items = NotificationMail::take($this->userId);
        $user = User::query()->find($this->userId);

        if ($user === null || $items === [] || ! MailSettings::enabled()) {
            return;
        }

        $items = array_values(array_filter(
            $items,
            fn (array $item) => NotificationMail::kindAllowed($item['kind']) && NotificationMail::wants($user, $item['kind']),
        ));

        if ($items === []) {
            return;
        }

        SendTemplatedMail::dispatch('notification-digest', $user->email, [
            'name' => $user->name,
            'summary' => NotificationMail::summarise($items, NotificationMail::titlesAllowed()),
            'open_link' => route('dashboard'),
            // Not a template placeholder: added to the fixed footer.
            'unsubscribe_link' => NotificationMail::unsubscribeLink($user, 'all'),
        ]);
    }
}
