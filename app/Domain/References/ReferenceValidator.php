<?php

namespace App\Domain\References;

use InvalidArgumentException;

/**
 * Validates, normalizes and links CVE/CWE/CAPEC/ATT&CK identifiers and
 * framework controls (schema.md §3.12, security.md §7 "External reference identifiers").
 * **No outbound API calls, ever** — nothing is fetched, nothing is
 * enriched; every method here is a pure string/regex function of its
 * arguments.
 *
 * `deriveUrl()` takes only a kind and an identifier — there is no `$url`
 * parameter for it to accept, by construction — so a `cve`/`cwe`/`capec`/
 * `attack_technique`/`control` reference can never carry an attacker-chosen URL in
 * from the request. Only `url` and `other` carry a URL, and that comes
 * straight from validated Form Request input (StoreNodeReferenceRequest),
 * never through this class.
 */
final class ReferenceValidator
{
    /** Kinds whose URL is derived from the identifier, never taken from input. */
    public const DERIVED_KINDS = ['cve', 'cwe', 'capec', 'attack_technique', 'control'];

    /** Kinds only a defence may carry (PLAN-2.7 D64). */
    public const DEFENCE_ONLY_KINDS = ['control'];

    /** Kinds that carry their own user-supplied URL. */
    public const URL_KINDS = ['url', 'other'];

    /** @return list<string> every configured kind, in config declaration order */
    public static function kinds(): array
    {
        /** @var array<string, mixed> $kinds */
        $kinds = config('arguesecure.references.kinds', []);

        return array_keys($kinds);
    }

    public static function label(string $kind): string
    {
        return (string) config("arguesecure.references.kinds.{$kind}.label", $kind);
    }

    public static function primaryKind(): string
    {
        return (string) config('arguesecure.references.primary_kind', 'cve');
    }

    private static function pattern(string $kind): ?string
    {
        /** @var ?string $pattern */
        $pattern = config("arguesecure.references.kinds.{$kind}.pattern");

        return $pattern;
    }

    /**
     * `other` has no configured pattern — any non-blank identifier is
     * accepted, matching schema.md §3.12's "identifier required for every
     * kind except url" with no further shape imposed. Every other kind
     * must match its configured regex exactly.
     */
    public static function isValidIdentifier(string $kind, string $identifier): bool
    {
        // `control` has no single pattern: the framework picks one (D64).
        if ($kind === 'control') {
            return ControlId::parse($identifier) !== null;
        }

        $pattern = self::pattern($kind);
        if ($pattern === null) {
            return trim($identifier) !== '';
        }

        return preg_match($pattern, $identifier) === 1;
    }

    public static function isValidUrl(string $url): bool
    {
        $pattern = self::pattern('url');

        return $pattern !== null && preg_match($pattern, $url) === 1;
    }

    /**
     * "Normalized to uppercase" (schema.md §3.12) — applies to every kind
     * except `url`, whose value is a URL, not an identifier, and must keep
     * its original case.
     */
    public static function normalizeIdentifier(string $kind, string $identifier): string
    {
        $trimmed = trim($identifier);

        return $kind === 'url' ? $trimmed : strtoupper($trimmed);
    }

    /**
     * Derives the link for a kind whose URL is a pure function of its
     * identifier (schema.md §3.12's table). Never call this for `url` or
     * `other` — their URL comes from validated request input instead; the
     * caller (StoreNodeReferenceRequest / ReferenceController) branches on
     * kind before reaching here.
     */
    public static function deriveUrl(string $kind, string $identifier): string
    {
        return match ($kind) {
            'cve' => "https://nvd.nist.gov/vuln/detail/{$identifier}",
            'cwe' => sprintf('https://cwe.mitre.org/data/definitions/%d.html', self::numericPart($identifier)),
            'capec' => sprintf('https://capec.mitre.org/data/definitions/%d.html', self::numericPart($identifier)),
            'attack_technique' => self::attackTechniqueUrl($identifier),
            'control' => self::controlUrl($identifier),
            default => throw new InvalidArgumentException(
                "Kind '{$kind}' does not derive a URL; its URL must come from validated request input."
            ),
        };
    }

    /**
     * The framework's page, never the control's (D64). Only reached after
     * validation, so an unparseable identifier here is a caller's bug.
     */
    private static function controlUrl(string $identifier): string
    {
        $control = ControlId::parse($identifier)
            ?? throw new InvalidArgumentException("'{$identifier}' is not a valid control citation.");

        return $control->url();
    }

    private static function numericPart(string $identifier): int
    {
        preg_match('/\d+/', $identifier, $matches);

        return (int) ($matches[0] ?? 0);
    }

    /**
     * `T1566` -> techniques/T1566/; `T1566.001` -> techniques/T1566/001/ —
     * MITRE's own URL shape nests a sub-technique under its parent
     * technique's path rather than dot-joining it.
     */
    private static function attackTechniqueUrl(string $identifier): string
    {
        [$base, $sub] = array_pad(explode('.', $identifier, 2), 2, null);

        return $sub === null
            ? "https://attack.mitre.org/techniques/{$base}/"
            : "https://attack.mitre.org/techniques/{$base}/{$sub}/";
    }
}
