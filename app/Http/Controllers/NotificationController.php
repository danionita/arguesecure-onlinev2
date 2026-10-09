<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The notification feed (W47, item 15).
 *
 * ## Authorization is the notifiable, not a policy
 *
 * Every query here is scoped to `$request->user()->notifications()`. There is
 * no assessment in the route and therefore nothing for `AssessmentPolicy` to
 * decide — the only question is whose feed this is, and the relation answers
 * it before the database does. A notification id belonging to someone else
 * finds no row and 404s, which is the right answer: 403 would confirm that the
 * id exists.
 *
 * ## In-app only
 *
 * No email. That needs a mail configuration, a digest policy and an
 * unsubscribe path, and a half-built one is worse than none (PLAN-2.2.md).
 */
class NotificationController extends Controller
{
    /** A panel is scanned, not read. The dashboard carries the longer list. */
    private const LIMIT = 20;

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $notifications = $user->notifications()
            ->latest()
            ->limit(self::LIMIT)
            ->get();

        return response()->json([
            'notifications' => $notifications
                ->map(fn (DatabaseNotification $notification): array => $this->toArray($notification))
                ->values()
                ->all(),
            'unread' => $user->unreadNotifications()->count(),
        ]);
    }

    /**
     * Marks the whole feed read.
     *
     * The panel calls this on open, which is what "the badge count is unread
     * only" means in practice: opening the list is the act of having seen it.
     * The rows stay visible after — only the badge clears — so nothing is lost
     * by not reading each one individually.
     */
    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->unreadNotifications->markAsRead();

        return response()->json(['unread' => 0]);
    }

    /**
     * Marks one notification read.
     *
     * Resolved through `$user->notifications()`, never through
     * `DatabaseNotification::find()`. Someone else's id finds no row in this
     * user's relation and 404s — which is the honest answer, where a 403 would
     * confirm the id exists and belongs to somebody.
     */
    public function markRead(Request $request, string $notification): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var DatabaseNotification $row */
        $row = $user->notifications()->whereKey($notification)->firstOrFail();
        $row->markAsRead();

        return response()->json(['unread' => $user->unreadNotifications()->count()]);
    }

    /**
     * The wire shape, which is deliberately flat.
     *
     * `data` already differs between the two kinds, and the panel switches on
     * `kind` rather than on the notification's PHP class name — the class name
     * is an implementation detail that would put `App\Domain\Notifications\…`
     * in a JSON payload and break every consumer if the class ever moved.
     *
     * @return array<string, mixed>
     */
    private function toArray(DatabaseNotification $notification): array
    {
        /** @var array<string, mixed> $data */
        $data = $notification->data;

        return [
            'id' => $notification->id,
            'read' => $notification->read_at !== null,
            'created_at' => $notification->created_at?->toJSON(),
            ...$data,
        ];
    }
}
