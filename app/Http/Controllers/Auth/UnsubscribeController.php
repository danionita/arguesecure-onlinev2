<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Audit\SecurityEvent;
use App\Domain\Audit\SecurityLog;
use App\Domain\Mail\NotificationMail;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The page an "unsubscribe" link opens. The link is signed, so it names exactly
 * one person and one kind and cannot be edited; the page shows a button and
 * only the button changes anything, so a mail scanner that fetches the link
 * unsubscribes nobody.
 */
class UnsubscribeController extends Controller
{
    // The id is not route-bound: a bound model would answer 404 for an id that
    // does not exist before the signature is checked, which tells a stranger
    // which ids are accounts.
    public function show(int $user, string $kind): Response
    {
        abort_unless($kind === 'all' || isset(NotificationMail::KINDS[$kind]), 404);

        return Inertia::render('Auth/Unsubscribe', [
            'label' => $kind === 'all' ? 'All notification emails' : NotificationMail::KINDS[$kind]['label'],
            'action' => request()->fullUrl(),
        ]);
    }

    public function store(int $user, string $kind): RedirectResponse
    {
        abort_unless($kind === 'all' || isset(NotificationMail::KINDS[$kind]), 404);

        $user = User::query()->findOrFail($user);

        NotificationMail::turnOff($user, $kind);

        SecurityLog::record(SecurityEvent::MailUnsubscribed, null, ['type' => 'user', 'id' => $user->id, 'email' => $user->email], ['kind' => $kind]);

        return redirect()->route('login')->with('status', 'You will not get those emails any more. You can turn them back on in your profile.');
    }
}
