<?php

namespace App\Domain\References;

/**
 * A framework control cited by a defence (PLAN-2.7 D64): the identifier of a
 * `control` reference, `FRAMEWORK:CONTROL` — `ISO27001-2022:A.5.23`,
 * `NIS2:ART.21(2)(D)`. The framework must be one configured under
 * `arguesecure.references.frameworks`, and the control part must match that
 * framework's own pattern. Like ReferenceValidator, a pure function of its
 * input and the config: nothing is fetched.
 *
 * The identifier is stored uppercased, as every reference identifier is
 * (schema.md §3.12), so the uniqueness index sees `Art.32` and `ART.32` as
 * one citation. `label()` is the readable form for the report, and only
 * ever display.
 */
final readonly class ControlId
{
    private function __construct(
        public string $framework,
        public string $control,
    ) {}

    /** Null unless the identifier is a valid, already-normalized citation. */
    public static function parse(string $identifier): ?self
    {
        $parts = explode(':', $identifier, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$framework, $control] = $parts;
        $pattern = self::framework($framework)['pattern'] ?? null;
        if (! is_string($pattern) || preg_match($pattern, $control) !== 1) {
            return null;
        }

        return new self($framework, $control);
    }

    /** @return list<string> the configured framework codes, in config order */
    public static function frameworks(): array
    {
        /** @var array<string, mixed> $frameworks */
        $frameworks = config('arguesecure.references.frameworks', []);

        return array_keys($frameworks);
    }

    public static function frameworkLabel(string $framework): string
    {
        return (string) (self::framework($framework)['label'] ?? $framework);
    }

    /** The framework's own page; never a link to one control (D64). */
    public static function frameworkUrl(string $framework): string
    {
        return (string) (self::framework($framework)['url'] ?? '');
    }

    public function url(): string
    {
        return self::frameworkUrl($this->framework);
    }

    /**
     * `ART.21(2)(D)` reads as `Art. 21(2)(d)` and `ANNEX.III` as
     * `Annex III`, the way the regulations number themselves. Every other
     * control part is shown as stored.
     */
    public function label(): string
    {
        if (preg_match('/^ART\.(\d+)(.*)$/', $this->control, $m) === 1) {
            return 'Art. '.$m[1].strtolower($m[2]);
        }
        if (preg_match('/^ANNEX\.([IVX]+)$/', $this->control, $m) === 1) {
            return 'Annex '.$m[1];
        }

        return $this->control;
    }

    /** @return ?array<string, mixed> */
    private static function framework(string $framework): ?array
    {
        /** @var array<string, array<string, mixed>> $frameworks */
        $frameworks = config('arguesecure.references.frameworks', []);

        return $frameworks[$framework] ?? null;
    }
}
