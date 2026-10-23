<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounts\InstanceSettings;
use App\Domain\Audit\SecurityEvent;
use App\Domain\Audit\SecurityLog;
use App\Domain\Identity\Oidc\OidcClient;
use App\Domain\Identity\Oidc\OidcConfig;
use App\Domain\Identity\Oidc\OidcException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAuthenticationRequest;
use App\Models\InstanceSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * W133, D105-D108. The sign-in settings: *Require two-factor authentication*,
 * *Sign in with SSO only* and the OpenID Connect provider. Enforcement of the
 * two switches is unchanged (EnsureTwoFactorEnrolled, InstanceSettings::ssoOnly);
 * only where they are edited moved here from Instance settings.
 */
class AuthenticationController extends Controller
{
    /** Stored column for each field the tab can set. */
    private const STORED = [
        'issuer' => 'oidc_issuer',
        'client_id' => 'oidc_client_id',
        'button_label' => 'oidc_button_label',
        'allowed_domains' => 'oidc_allowed_domains',
    ];

    public function edit(): Response
    {
        $settings = InstanceSettings::all();
        $row = InstanceSetting::singleton();

        return Inertia::render('Admin/Authentication', [
            'settings' => [
                'require_two_factor' => $settings['require_two_factor'],
                'sso_only' => $settings['sso_only'],
            ],
            'oidc' => [
                'issuer' => $this->field('issuer', OidcConfig::issuer()),
                'client_id' => $this->field('client_id', OidcConfig::clientId()),
                'button_label' => $this->field('button_label', OidcConfig::source('button_label') === OidcConfig::SOURCE_NONE ? '' : OidcConfig::buttonLabel()),
                'allowed_domains' => $this->field('allowed_domains', implode(', ', OidcConfig::allowedDomains())),
                'trust_email' => $this->field('trust_email', OidcConfig::trustEmail()),
                // Never the secret itself: only whether one is set, and where.
                'client_secret' => ['set' => OidcConfig::clientSecret() !== '', 'source' => OidcConfig::source('client_secret')],
            ],
            'enabled' => OidcConfig::enabled(),
            'redirectUri' => route('oidc.callback'),
        ]);
    }

    public function update(UpdateAuthenticationRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $changed = [];

        DB::transaction(function () use ($validated, &$changed) {
            $row = InstanceSetting::singleton();
            $wasSsoOnly = $row->sso_only;

            foreach (['require_two_factor', 'sso_only'] as $switch) {
                if ($row->{$switch} !== (bool) $validated[$switch]) {
                    $changed[] = $switch;
                }
                $row->{$switch} = (bool) $validated[$switch];
            }

            foreach (self::STORED as $field => $column) {
                // Environment wins; and a key the request leaves out is left
                // alone, not cleared (the old Settings form's `sometimes` rule).
                if (OidcConfig::source($field) === OidcConfig::SOURCE_ENVIRONMENT || ! array_key_exists($column, $validated)) {
                    continue;
                }

                $value = $field === 'allowed_domains'
                    ? $this->normaliseDomains((string) $validated[$column])
                    : trim((string) $validated[$column]);
                $value = $value === '' ? null : $value;

                if ($row->{$column} !== $value) {
                    $changed[] = $column;
                }
                $row->{$column} = $value;
            }

            if (OidcConfig::source('trust_email') !== OidcConfig::SOURCE_ENVIRONMENT && array_key_exists('oidc_trust_email', $validated)) {
                $trust = (bool) $validated['oidc_trust_email'];
                if ($row->oidc_trust_email !== $trust) {
                    $changed[] = 'oidc_trust_email';
                }
                $row->oidc_trust_email = $trust;
            }

            if (OidcConfig::source('client_secret') !== OidcConfig::SOURCE_ENVIRONMENT) {
                $secret = (string) ($validated['oidc_client_secret'] ?? '');

                if ($secret !== '') {
                    $row->oidc_client_secret = $secret;
                    $changed[] = 'oidc_client_secret';
                } elseif ((bool) ($validated['clear_client_secret'] ?? false) && $row->oidc_client_secret !== null) {
                    $row->oidc_client_secret = null;
                    $changed[] = 'oidc_client_secret';
                }
            }

            $row->save();

            // D108: SSO-only with no working provider would refuse every
            // non-admin password and offer nothing instead. Rolled back. Only
            // the switch going on is refused: one already on whose provider
            // has since gone must not block saving the other switch.
            if ($row->sso_only && ! $wasSsoOnly && ! OidcConfig::enabled()) {
                throw ValidationException::withMessages([
                    'sso_only' => 'Set the issuer, client ID and client secret before turning on SSO only.',
                ]);
            }
        });

        // Field names only: no value, least of all the secret, is logged.
        SecurityLog::record(SecurityEvent::SettingsUpdated, $request->user(), null, ['settings' => ['tab' => 'authentication', 'changed' => $changed]]);

        return redirect()->route('admin.authentication.edit');
    }

    /**
     * D107: fetch the saved provider's discovery document and say what was
     * found, or why not. Sends no credentials.
     */
    public function check(OidcClient $client): JsonResponse
    {
        if (OidcConfig::issuer() === '') {
            return response()->json(['ok' => false, 'message' => 'Save an issuer first.']);
        }

        try {
            $document = $client->discovery(fresh: true);
        } catch (OidcException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()]);
        } catch (\Throwable) {
            return response()->json(['ok' => false, 'message' => 'The provider could not be reached.']);
        }

        return response()->json([
            'ok' => true,
            'message' => 'The provider answered.',
            'endpoints' => [
                'authorization' => $document['authorization_endpoint'],
                'token' => $document['token_endpoint'],
                'keys' => $document['jwks_uri'],
            ],
        ]);
    }

    /**
     * @return array{value: string|bool, source: string}
     */
    private function field(string $field, string|bool $value): array
    {
        return ['value' => $value, 'source' => OidcConfig::source($field)];
    }

    private function normaliseDomains(string $raw): string
    {
        $domains = array_filter(array_map(fn ($d) => strtolower(trim($d)), explode(',', $raw)));

        return implode(',', array_values(array_unique($domains)));
    }
}
