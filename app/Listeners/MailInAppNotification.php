<?php

namespace App\Listeners;

use App\Domain\Mail\NotificationMail;
use App\Models\User;
use Illuminate\Notifications\Events\NotificationSent;

/** Offers every in-app (database) notification to the mail channel's gates. */
class MailInAppNotification
{
    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database' || ! $event->notifiable instanceof User) {
            return;
        }

        $notification = $event->notification;
        $data = method_exists($notification, 'toArray') ? $notification->toArray($event->notifiable) : [];

        if (is_array($data)) {
            NotificationMail::record($event->notifiable, $data);
        }
    }
}
