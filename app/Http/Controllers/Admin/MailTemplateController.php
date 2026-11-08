<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\SecurityEvent;
use App\Domain\Audit\SecurityLog;
use App\Domain\Mail\MailTemplates;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateMailTemplateRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Edit, preview and reset the wording of each message kind. `{kind}` is looked
 * up in a fixed list; an unknown one is a 404, and it is never used to build a
 * class name, a column or a path.
 */
class MailTemplateController extends Controller
{
    public function update(UpdateMailTemplateRequest $request, string $kind): RedirectResponse
    {
        MailTemplates::save($kind, $request->validated('subject'), $request->validated('body'));

        // The kind only: the wording is not secret, but nothing needs it in the log.
        SecurityLog::record(SecurityEvent::MailTemplateUpdated, $request->user(), null, ['kind' => $kind]);

        return redirect()->route('admin.mail.edit');
    }

    public function destroy(Request $request, string $kind): RedirectResponse
    {
        abort_unless(MailTemplates::exists($kind), 404);

        MailTemplates::reset($kind);

        SecurityLog::record(SecurityEvent::MailTemplateReset, $request->user(), null, ['kind' => $kind]);

        return redirect()->route('admin.mail.edit');
    }

    /**
     * Renders the posted (unsaved) wording with sample values and sends
     * nothing.
     */
    public function preview(UpdateMailTemplateRequest $request, string $kind): JsonResponse
    {
        $rendered = MailTemplates::render($kind, MailTemplates::sample($kind), [
            'subject' => $request->validated('subject'),
            'body' => $request->validated('body'),
        ]);

        return response()->json(['subject' => $rendered['subject'], 'text' => $rendered['text']]);
    }
}
