<?php

namespace Tests\Feature\Assistant\Support;

use InvalidArgumentException;

/**
 * Reads `tests/fixtures/assistant/injections.json` — the 2.3 spike's red-team
 * measurement (W53).
 *
 * The fixture is a *record*, not a configuration. Its `outcome` values say
 * what three model families did on a specific day on a specific machine, and
 * `GuardrailTest` pins the two that failed. Nothing here may quietly
 * reinterpret it: `case()` throws on an unknown id rather than returning
 * something empty, because a guardrail test that silently tests nothing is the
 * failure mode this whole package exists to rule out.
 *
 * @phpstan-type InjectionCase array{
 *     id: string, job: string, channel: string, text: string, marker: string,
 *     why: string, competes_with_the_task: bool,
 *     outcome: array<string, string>, mitigation: string, complied_reply: string
 * }
 */
final class Injections
{
    public const HELD = 'held';

    public const COMPLIED = 'complied';

    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    /** @return array<string, mixed> */
    public static function all(): array
    {
        if (self::$data === null) {
            $raw = file_get_contents(base_path('tests/fixtures/assistant/injections.json'));
            $decoded = json_decode((string) $raw, true);

            if (! is_array($decoded)) {
                throw new InvalidArgumentException('tests/fixtures/assistant/injections.json is not valid JSON.');
            }

            self::$data = $decoded;
        }

        return self::$data;
    }

    /** @return list<InjectionCase> */
    public static function cases(?string $job = null): array
    {
        /** @var list<InjectionCase> $cases */
        $cases = self::all()['cases'];

        return array_values(array_filter(
            $cases,
            fn (array $case): bool => $job === null || $case['job'] === $job,
        ));
    }

    /** @return InjectionCase */
    public static function case(string $id): array
    {
        foreach (self::cases() as $case) {
            if ($case['id'] === $id) {
                return $case;
            }
        }

        throw new InvalidArgumentException("No injection case '{$id}' in the fixture.");
    }

    /** @return list<string> */
    public static function models(): array
    {
        /** @var list<string> $models */
        $models = self::all()['measured']['models'];

        return $models;
    }

    public static function canary(): string
    {
        return (string) self::all()['canary'];
    }

    /** True when the case's payload appears in the given text at all. */
    public static function detect(string $caseId, string $text): bool
    {
        return preg_match('/'.self::case($caseId)['marker'].'/i', $text) === 1;
    }
}
