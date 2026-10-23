<?php

namespace App\Domain\Audit;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Masks secrets in every log record, on every channel (security.md §11:
 * "redact share_token and password from all log context via a Monolog
 * processor"). config/logging.php taps it onto each channel (RedactLogs),
 * so it holds for a line the application never meant to be sensitive: an
 * exception's context, a payload logged while debugging, a future caller's
 * `details`.
 *
 * Two rules:
 *  - a context or extra key naming a secret (password, token, secret, session
 *    id, cookie, recovery code …) has its value replaced, at any depth;
 *  - a share-link path, `/s/<token>`, is masked wherever it appears in a
 *    string — the message included — because the token is a bearer
 *    credential and a URL is the shape in which it is most likely to be
 *    logged by accident (a payload's `share_link`, a request path).
 *
 * The key pattern matches a whole underscore-separated word, so
 * `session_lifetime_minutes` (an admin setting worth logging) is kept while
 * `session_id` and `password_confirmation` are not.
 */
class RedactSensitiveData implements ProcessorInterface
{
    public const MASK = '[redacted]';

    private const SECRET_KEY = '/(^|_)(password|passwd|pass|token|secret|session|session_?id|cookie|authorization|recovery_codes?|otp|code|code_verifier|key|private_key|credentials?|bearer|jwt)(_confirmation)?$/i';

    // The token is 43 base64url characters (ShareTokenService); anything from
    // 16 up is masked so a truncated or padded token is caught too.
    private const SHARE_PATH = '#/s/[A-Za-z0-9_-]{16,}#';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->scrubString($record->message),
            context: $this->scrub($record->context),
            extra: $this->scrub($record->extra),
        );
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private function scrub(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEY, $key) === 1 && $value !== null) {
                $data[$key] = self::MASK;
            } elseif (is_array($value)) {
                $data[$key] = $this->scrub($value);
            } elseif (is_string($value)) {
                $data[$key] = $this->scrubString($value);
            }
        }

        return $data;
    }

    private function scrubString(string $value): string
    {
        return preg_replace(self::SHARE_PATH, '/s/'.self::MASK, $value) ?? $value;
    }
}
