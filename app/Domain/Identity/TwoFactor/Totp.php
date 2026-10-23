<?php

namespace App\Domain\Identity\TwoFactor;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Carbon;
use PragmaRX\Google2FA\Google2FA;

/**
 * RFC 6238 time-based codes (D77), a thin wrapper over `pragmarx/google2fa`.
 *
 * The step is computed here from Laravel's clock rather than left to the
 * library's `time()`, so a test that fixes the clock with
 * `Carbon::setTestNow()` fixes the codes too.
 */
final class Totp
{
    /** The 30-second step authenticator apps assume (RFC 6238 §4.1). */
    public const PERIOD = 30;

    /**
     * Steps accepted on either side of the current one: a phone whose clock
     * is half a minute off still works, one a minute off does not.
     */
    public const WINDOW = 1;

    private readonly Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA;
        $this->google2fa->setKeyRegeneration(self::PERIOD);
    }

    /** 160 bits, base32: what an authenticator app is given as text. */
    public function newSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function currentStep(): int
    {
        return intdiv(Carbon::now()->getTimestamp(), self::PERIOD);
    }

    /**
     * The step `$code` is valid for, or null. Only steps greater than
     * `$lastStep` count, so a code that has been accepted once is refused
     * again for as long as it would otherwise be valid.
     */
    public function matchingStep(string $secret, string $code, ?int $lastStep): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (! preg_match('/^\d{6}$/', $code)) {
            return null;
        }

        $step = $this->google2fa->verifyKeyNewer(
            $secret,
            $code,
            $lastStep ?? 0,
            self::WINDOW,
            $this->currentStep(),
        );

        return is_int($step) ? $step : null;
    }

    /** The `otpauth://` URI an authenticator app reads from the QR code. */
    public function provisioningUri(string $secret, string $account): string
    {
        return $this->google2fa->getQRCodeUrl((string) config('app.name'), $account, $secret);
    }

    /**
     * The QR code for `$uri` as an SVG data URI, drawn here so no QR library
     * ships to the browser and the image stays inside the CSP's
     * `img-src 'self' data:`.
     */
    public function qrDataUri(string $uri): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd)))->writeString($uri);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /** The code for `$step`: only tests and the e2e scenario need it. */
    public function codeAt(string $secret, int $step): string
    {
        return $this->google2fa->oathTotp($secret, $step);
    }
}
