<?php

/*
 * The standards templates (2.5.1) claim to cover their whole standard. This
 * is the claim, written out: every control, clause or criterion each
 * standard defines is listed here by identifier — independently of the
 * fixture, so that dropping one from the file turns this red instead of
 * shrinking the expectation with it.
 *
 * A control is covered when a defence's title starts with its identifier
 * followed by a space, so "A.5.1" does not accidentally match "A.5.10".
 * Every identifier must appear exactly once: a control listed twice would
 * split its links across two nodes and double-count in the Statement of
 * Applicability a user builds from it.
 */

/** @return array<string, mixed> */
function standardFixture(string $key): array
{
    $path = database_path("seeders/templates/{$key}.json");

    return json_decode((string) file_get_contents($path), true);
}

/**
 * @param  list<string>  $ids
 */
function expectEveryIdOnce(string $key, array $ids): void
{
    $titles = array_column(standardFixture($key)['defences'], 'title');

    foreach ($ids as $id) {
        $matches = array_filter($titles, fn (string $t): bool => str_starts_with($t, $id.' '));
        expect(count($matches))->toBe(1, "{$key}: '{$id}' should appear exactly once, found ".count($matches));
    }
}

/** @return list<string> */
function managementSystemClauses(bool $withImpactAssessment): array
{
    $clauses = ['4.1', '4.2', '4.3', '4.4', '5.1', '5.2', '5.3', '6.1.1', '6.1.2', '6.1.3'];
    if ($withImpactAssessment) {
        $clauses[] = '6.1.4';
    }
    $clauses = [...$clauses, '6.2', '6.3', '7.1', '7.2', '7.3', '7.4', '7.5', '8.1', '8.2', '8.3'];
    if ($withImpactAssessment) {
        $clauses[] = '8.4';
    }

    return array_map(fn (string $c): string => "Clause {$c}", [...$clauses, '9.1', '9.2', '9.3', '10.1', '10.2']);
}

/** @return list<string> */
function numbered(string $prefix, int $from, int $to): array
{
    return array_map(fn (int $n): string => "{$prefix}{$n}", range($from, $to));
}

test('ISO/IEC 27001:2022 covers all 93 Annex A controls and every ISMS clause', function () {
    $annexA = [
        ...numbered('A.5.', 1, 37),
        ...numbered('A.6.', 1, 8),
        ...numbered('A.7.', 1, 14),
        ...numbered('A.8.', 1, 34),
    ];
    expect($annexA)->toHaveCount(93);

    expectEveryIdOnce('standard-iso27001-2022', [...$annexA, ...managementSystemClauses(false)]);
});

test('ISO/IEC 42001:2023 covers all 38 Annex A controls and every AIMS clause', function () {
    $annexA = [
        'A.2.2', 'A.2.3', 'A.2.4',
        'A.3.2', 'A.3.3',
        'A.4.2', 'A.4.3', 'A.4.4', 'A.4.5', 'A.4.6',
        'A.5.2', 'A.5.3', 'A.5.4', 'A.5.5',
        'A.6.1.2', 'A.6.1.3', 'A.6.2.2', 'A.6.2.3', 'A.6.2.4', 'A.6.2.5', 'A.6.2.6', 'A.6.2.7', 'A.6.2.8',
        'A.7.2', 'A.7.3', 'A.7.4', 'A.7.5', 'A.7.6',
        'A.8.2', 'A.8.3', 'A.8.4', 'A.8.5',
        'A.9.2', 'A.9.3', 'A.9.4',
        'A.10.2', 'A.10.3', 'A.10.4',
    ];
    expect($annexA)->toHaveCount(38);

    expectEveryIdOnce('standard-iso42001-2023', [...$annexA, ...managementSystemClauses(true)]);
});

test('the EU AI Act template covers every obligation that binds an operator', function () {
    // Chapters I–V and the operator-facing articles of IX (post-market
    // monitoring, incident reporting, the right to explanation). Articles
    // addressed to Member States, notified bodies, the AI Office and the
    // Commission are out of scope by design — see the fixture description.
    $articles = [
        '4', '5', '6', '8', '9', '10', '11', '12', '13', '14', '15',
        '16', '17', '18', '19', '20', '21', '22', '23', '24', '25', '26', '27',
        '43', '47', '48', '49', '50', '53', '54', '55', '72', '73', '86',
    ];

    expectEveryIdOnce('standard-eu-ai-act', array_map(fn (string $a): string => "Art. {$a}", $articles));
});

test('SOC 2 covers every Trust Services Criterion in all five categories', function () {
    $criteria = [
        ...numbered('CC1.', 1, 5), ...numbered('CC2.', 1, 3), ...numbered('CC3.', 1, 4),
        ...numbered('CC4.', 1, 2), ...numbered('CC5.', 1, 3), ...numbered('CC6.', 1, 8),
        ...numbered('CC7.', 1, 5), 'CC8.1', ...numbered('CC9.', 1, 2),
        ...numbered('A1.', 1, 3),
        ...numbered('C1.', 1, 2),
        ...numbered('PI1.', 1, 5),
        'P1.1', 'P2.1', 'P3.1', 'P3.2', ...numbered('P4.', 1, 3), 'P5.1', 'P5.2',
        ...numbered('P6.', 1, 7), 'P7.1', 'P8.1',
    ];
    expect($criteria)->toHaveCount(61);

    expectEveryIdOnce('standard-soc2', $criteria);
});

test('GDPR covers every controller and processor obligation', function () {
    // Chapters II to V. Article 23 (restrictions by Member State law) and
    // Articles 41 and 43 (monitoring and certification bodies) bind someone
    // other than the controller or processor, and are out of scope.
    $articles = [
        ...range(5, 22), ...range(24, 40), 42, ...range(44, 49),
    ];
    expect($articles)->toHaveCount(42);

    expectEveryIdOnce('standard-gdpr', array_map(fn (int $a): string => "Art. {$a}", $articles));
});

test('NIS2 covers every obligation of an essential or important entity', function () {
    $obligations = [
        'Art. 3(4)', 'Art. 20(1)', 'Art. 20(2)', 'Art. 21(1)',
        ...array_map(fn (string $l): string => "Art. 21(2)({$l})", range('a', 'j')),
        'Art. 21(3)', 'Art. 21(4)', 'Art. 23(1)', 'Art. 23(2)',
        ...array_map(fn (string $l): string => "Art. 23(4)({$l})", range('a', 'e')),
        'Art. 24', 'Art. 27', 'Art. 28', 'Art. 29', 'Art. 30', 'Art. 32', 'Art. 33',
    ];
    expect($obligations)->toHaveCount(30);

    expectEveryIdOnce('standard-nis2', $obligations);
});

test('CIS Controls v8.1 covers all 153 Safeguards and marks the 56 in IG1', function () {
    $perControl = [1 => 5, 2 => 7, 3 => 14, 4 => 12, 5 => 6, 6 => 8, 7 => 7, 8 => 12, 9 => 7,
        10 => 7, 11 => 5, 12 => 8, 13 => 11, 14 => 9, 15 => 7, 16 => 14, 17 => 9, 18 => 5];
    $safeguards = [];
    foreach ($perControl as $control => $count) {
        $safeguards = [...$safeguards, ...numbered("{$control}.", 1, $count)];
    }
    expect($safeguards)->toHaveCount(153);

    expectEveryIdOnce('standard-cis-controls-v8', $safeguards);

    $ig1 = array_filter(
        standardFixture('standard-cis-controls-v8')['defences'],
        fn (array $d): bool => str_starts_with($d['description'], 'IG1 '),
    );
    expect($ig1)->toHaveCount(56);
});

test('NIST CSF 2.0 covers all 106 Subcategories of its six Functions', function () {
    // NIST CSWP 29, Appendix A. Numbering has gaps where CSF 1.1
    // Subcategories were withdrawn or moved (ID.AM-06, PR.DS-03 to -09, ...);
    // the gaps are the publication's, not omissions.
    $perCategory = [
        'GV.OC' => [1, 2, 3, 4, 5], 'GV.RM' => [1, 2, 3, 4, 5, 6, 7], 'GV.RR' => [1, 2, 3, 4],
        'GV.PO' => [1, 2], 'GV.OV' => [1, 2, 3], 'GV.SC' => range(1, 10),
        'ID.AM' => [1, 2, 3, 4, 5, 7, 8], 'ID.RA' => range(1, 10), 'ID.IM' => [1, 2, 3, 4],
        'PR.AA' => [1, 2, 3, 4, 5, 6], 'PR.AT' => [1, 2], 'PR.DS' => [1, 2, 10, 11],
        'PR.PS' => [1, 2, 3, 4, 5, 6], 'PR.IR' => [1, 2, 3, 4],
        'DE.CM' => [1, 2, 3, 6, 9], 'DE.AE' => [2, 3, 4, 6, 7, 8],
        'RS.MA' => [1, 2, 3, 4, 5], 'RS.AN' => [3, 6, 7, 8], 'RS.CO' => [2, 3], 'RS.MI' => [1, 2],
        'RC.RP' => [1, 2, 3, 4, 5, 6], 'RC.CO' => [3, 4],
    ];
    $subcategories = [];
    foreach ($perCategory as $category => $numbers) {
        foreach ($numbers as $n) {
            $subcategories[] = sprintf('%s-%02d', $category, $n);
        }
    }
    expect($subcategories)->toHaveCount(106);

    expectEveryIdOnce('standard-nist-csf-2', $subcategories);
});

test('the Cyber Resilience Act covers every essential requirement and manufacturer obligation', function () {
    // Annex I: Part I point (1) and points (2)(a) to (m), Part II points (1)
    // to (8). Article 13: the manufacturer's obligations, leaving out (1)
    // (Annex I itself), (10) and (11) (options, not obligations) and (24)
    // and (25) (powers of the Commission and ADCO). Article 14: notifying
    // exploited vulnerabilities (1) and severe incidents (3), with (2) and
    // (4) as their deadlines, and informing users (8).
    $obligations = [
        ...array_map(fn (int $p): string => "Art. 13({$p})", [2, 3, 4, 5, 6, 7, 8, 9, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23]),
        'Art. 14(1)', 'Art. 14(3)', 'Art. 14(8)',
        'Annex I Part I(1)',
        ...array_map(fn (string $l): string => "Annex I Part I(2)({$l})", range('a', 'm')),
        ...array_map(fn (int $p): string => "Annex I Part II({$p})", range(1, 8)),
    ];
    expect($obligations)->toHaveCount(45);

    expectEveryIdOnce('standard-eu-cra', $obligations);
});
