<?php

namespace App\Domain\Mail;

use App\Models\MailTemplate;

/**
 * The kinds of message the application can send, their default wording, and the
 * administrator's edits of it.
 *
 * A template is plain text with `{{name}}` placeholders from a fixed list per
 * kind. It is never evaluated: no Blade, no PHP, no expression, only a lookup
 * of a name in the values the application passes in. Every value reaches the
 * plain part as text and the HTML part escaped; the values the application
 * itself made as links become links, and nothing an administrator types does.
 * A fixed footer, which the administrator cannot edit, explains why the person
 * got the message.
 */
final class MailTemplates
{
    public const SUBJECT_MAX = 200;

    public const BODY_MAX = 5000;

    /** What counts as a placeholder; validation and rendering both read it. */
    private const PLACEHOLDER = '/\{\{\s*([^{}]*?)\s*\}\}/';

    /**
     * kind => label, the placeholders it offers (name => what it stands for;
     * a name ending `_link` is a URL the application made), the sample values
     * for the preview, the defaults and the footer.
     *
     * @return array<string, array{label: string, required: list<string>, placeholders: array<string, string>, sample: array<string, string>, subject: string, body: string, footer: string}>
     */
    public static function kinds(): array
    {
        return [
            'invitation' => [
                'label' => 'Invitation',
                'required' => ['set_password_link'],
                'placeholders' => [
                    'name' => 'the person\'s name',
                    'app_name' => 'the application\'s name',
                    'set_password_link' => 'the one-time link to choose a password',
                    'expires_days' => 'how many days the link works',
                ],
                'sample' => ['name' => 'Alex Example', 'app_name' => 'ArgueSecure', 'set_password_link' => 'https://example.org/set-password/abc123', 'expires_days' => '7'],
                'subject' => 'You have an account on {{app_name}}',
                'body' => "Hello {{name}},\n\nAn account was created for you on {{app_name}}. Choose your password with this link, which works once and for {{expires_days}} days:\n\n{{set_password_link}}\n",
                'footer' => 'You are receiving this because an administrator created an account for this address.',
            ],
            'reminder' => [
                'label' => 'Invitation reminder',
                'required' => ['set_password_link'],
                'placeholders' => [
                    'name' => 'the person\'s name',
                    'app_name' => 'the application\'s name',
                    'set_password_link' => 'a new one-time link to choose a password',
                    'expires_days' => 'how many days the link works',
                ],
                'sample' => ['name' => 'Alex Example', 'app_name' => 'ArgueSecure', 'set_password_link' => 'https://example.org/set-password/abc123', 'expires_days' => '7'],
                'subject' => 'Your {{app_name}} account is waiting',
                'body' => "Hello {{name}},\n\nYour account on {{app_name}} has not been used yet. Choose your password with this new link, which works once and for {{expires_days}} days:\n\n{{set_password_link}}\n",
                'footer' => 'You are receiving this because an administrator created an account for this address and it has not been used. You will get at most two reminders after each invitation.',
            ],
            'verify-email' => [
                'label' => 'Email address verification',
                'required' => ['verify_link'],
                'placeholders' => [
                    'name' => 'the person\'s name',
                    'app_name' => 'the application\'s name',
                    'verify_link' => 'the one-time link that confirms the address',
                    'expires_hours' => 'how many hours the link works',
                ],
                'sample' => ['name' => 'Alex Example', 'app_name' => 'ArgueSecure', 'verify_link' => 'https://example.org/email/verify/abc123', 'expires_hours' => '24'],
                'subject' => 'Confirm your email address for {{app_name}}',
                'body' => "Hello {{name}},\n\nConfirm that this is your address by opening this link, which works once and for {{expires_hours}} hours:\n\n{{verify_link}}\n",
                'footer' => 'You are receiving this because this address was used to sign up. If it was not you, ignore this message and nothing happens.',
            ],
            'password-reset' => [
                'label' => 'Password reset',
                'required' => ['reset_link'],
                'placeholders' => [
                    'name' => 'the person\'s name',
                    'app_name' => 'the application\'s name',
                    'reset_link' => 'the one-time link to choose a new password',
                    'expires_minutes' => 'how many minutes the link works',
                ],
                'sample' => ['name' => 'Alex Example', 'app_name' => 'ArgueSecure', 'reset_link' => 'https://example.org/reset-password/abc123', 'expires_minutes' => '60'],
                'subject' => 'Reset your {{app_name}} password',
                'body' => "Hello {{name}},\n\nSomeone asked to reset the password for your account on {{app_name}}. If it was you, choose a new password with this link, which works once and for {{expires_minutes}} minutes:\n\n{{reset_link}}\n\nIf it was not you, ignore this message: your password has not changed.\n",
                'footer' => 'You are receiving this because a password reset was requested for this address.',
            ],
            'notification-digest' => [
                'label' => 'Notifications',
                'required' => ['summary', 'open_link'],
                'placeholders' => [
                    'name' => 'the person\'s name',
                    'app_name' => 'the application\'s name',
                    'summary' => 'what happened, one line each',
                    'open_link' => 'a link to the notifications in the application',
                ],
                'sample' => ['name' => 'Alex Example', 'app_name' => 'ArgueSecure', 'summary' => "- Sam mentioned you in a comment.\n- Sam assigned you a node.", 'open_link' => 'https://example.org/dashboard'],
                'subject' => 'New activity on {{app_name}}',
                'body' => "Hello {{name}},\n\n{{summary}}\n\nOpen {{app_name}} to see it:\n\n{{open_link}}\n",
                'footer' => 'You are receiving this because you switched on email for these notifications.',
            ],
        ];
    }

    /**
     * Placeholders a subject may use: not a link and not the summary. A
     * subject shows on a lock screen and lands in mail logs, so it must never
     * carry a one-time link or what happened.
     *
     * @return list<string>
     */
    public static function subjectPlaceholders(string $kind): array
    {
        return array_values(array_filter(
            array_keys(self::kinds()[$kind]['placeholders']),
            fn (string $name) => ! str_ends_with($name, '_link') && $name !== 'summary',
        ));
    }

    public static function exists(string $kind): bool
    {
        return array_key_exists($kind, self::kinds());
    }

    /**
     * The wording in force for a kind: the administrator's, else the default.
     *
     * @return array{subject: string, body: string, customised: bool}
     */
    public static function wording(string $kind): array
    {
        $definition = self::kinds()[$kind];
        $row = MailTemplate::query()->where('kind', $kind)->first();

        return [
            'subject' => $row instanceof MailTemplate ? $row->subject : $definition['subject'],
            'body' => $row instanceof MailTemplate ? $row->body : $definition['body'],
            'customised' => $row instanceof MailTemplate,
        ];
    }

    /**
     * Placeholders used in a text that the kind does not offer.
     *
     * @return list<string>
     */
    public static function unknownPlaceholders(string $kind, string $text, bool $subject = false): array
    {
        $allowed = $subject ? self::subjectPlaceholders($kind) : array_keys(self::kinds()[$kind]['placeholders']);

        return array_values(array_unique(array_filter(self::used($text), fn (string $name) => ! in_array($name, $allowed, true))));
    }

    /**
     * Placeholders the kind cannot work without (the one-time link) that the
     * body leaves out.
     *
     * @return list<string>
     */
    public static function missingRequired(string $kind, string $body): array
    {
        return array_values(array_diff(self::kinds()[$kind]['required'], self::used($body)));
    }

    /** @return list<string> */
    private static function used(string $text): array
    {
        preg_match_all(self::PLACEHOLDER, $text, $found);

        return $found[1];
    }

    /**
     * @param  array<string, string>  $values  by placeholder name
     * @param  array{subject: string, body: string}|null  $wording  render this instead of the stored wording (the preview)
     * @return array{subject: string, text: string, html: string, unsubscribe: string|null}
     */
    public static function render(string $kind, array $values, ?array $wording = null): array
    {
        $definition = self::kinds()[$kind];
        $wording ??= self::wording($kind);
        $values = array_map('strval', $values);

        $substitute = fn (string $template, callable $format) => preg_replace_callback(
            self::PLACEHOLDER,
            fn (array $m) => array_key_exists($m[1], $definition['placeholders']) ? $format($m[1], $values[$m[1]] ?? '') : '',
            $template,
        );

        // One line: a value with a line break would otherwise add a header.
        // A placeholder not allowed in a subject renders as nothing, whatever
        // is stored.
        $allowedInSubject = self::subjectPlaceholders($kind);
        $subject = trim(preg_replace('/\s+/', ' ', (string) $substitute(
            $wording['subject'],
            fn ($n, $v) => in_array($n, $allowedInSubject, true) ? $v : '',
        )));

        // Added by the application, never by a template: where to stop these.
        $unsubscribe = $values['unsubscribe_link'] ?? null;

        $text = $substitute($wording['body'], fn ($n, $v) => $v)."\n--\n".$definition['footer']."\n"
            .($unsubscribe !== null ? "To stop these emails: {$unsubscribe}\n" : '');

        $html = (string) $substitute(
            e($wording['body']),
            // Only a web address becomes a link, whatever the application passes.
            fn ($n, $v) => str_ends_with($n, '_link') && preg_match('~\Ahttps?://~i', $v) === 1
                ? '<a href="'.e($v).'">'.e($v).'</a>'
                : e($v),
        );
        // A multi-line value (a summary) and the template's own breaks both become <br>.
        $html = '<div style="font-family:sans-serif;white-space:normal">'.nl2br($html).'<hr><p style="color:#666;font-size:12px">'.e($definition['footer'])
            .($unsubscribe !== null ? '<br><a href="'.e($unsubscribe).'">Stop these emails</a>' : '').'</p></div>';

        return ['subject' => $subject, 'text' => $text, 'html' => $html, 'unsubscribe' => $unsubscribe];
    }

    /**
     * @return array<string, string>
     */
    public static function sample(string $kind): array
    {
        return self::kinds()[$kind]['sample'];
    }

    public static function save(string $kind, string $subject, string $body): void
    {
        $default = self::kinds()[$kind];

        // Saving the shipped text is not an edit: keeping no row lets a later
        // release's improved default reach this installation.
        // The request is trimmed and a browser sends CRLF, so the comparison
        // ignores both; the shipped body ends with a newline the form cannot.
        if (self::same($subject, $default['subject']) && self::same($body, $default['body'])) {
            self::reset($kind);

            return;
        }

        MailTemplate::query()->updateOrCreate(['kind' => $kind], ['subject' => $subject, 'body' => $body]);
    }

    private static function same(string $a, string $b): bool
    {
        return trim(str_replace("\r\n", "\n", $a)) === trim(str_replace("\r\n", "\n", $b));
    }

    public static function reset(string $kind): void
    {
        MailTemplate::query()->where('kind', $kind)->delete();
    }
}
