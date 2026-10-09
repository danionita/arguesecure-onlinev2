<?php

use App\Domain\Snapshots\SnapshotGraphCodec;

test('encode then decode round-trips the graph structure exactly', function () {
    $codec = new SnapshotGraphCodec;

    $graph = [
        'schemaVersion' => 1,
        'assessment' => ['uuid' => 'a-1', 'currency' => 'EUR', 'amortizationYears' => 3],
        'root' => ['title' => 'Demo', 'description' => null],
        'nodes' => [
            'risk-uuid' => ['uuid' => 'risk-uuid', 'type' => 'risk', 'title' => 'Breach', 'impact_amount' => 100000.5],
        ],
        'riskAttackLinks' => [
            ['risk_uuid' => 'risk-uuid', 'attack_uuid' => 'attack-uuid', 'position' => 0],
        ],
        'attackDefenceLinks' => [],
        'scales' => [['id' => 1, 'kind' => 'impact', 'unit' => 'currency', 'is_system' => true]],
        // A non-whole-number float, deliberately: JSON round-trips a
        // whole-number PHP float as a bare integer (json_encode omits
        // ".0" without JSON_PRESERVE_ZERO_FRACTION), which would make a
        // strict toBe() comparison fail on a type mismatch that has
        // nothing to do with the codec — see ReportContentTest's own
        // closeTo() docblock for the same JSON behaviour.
        'scaleLevels' => [['id' => 1, 'representative' => 31623.42]],
    ];

    $encoded = $codec->encode($graph);
    $decoded = $codec->decode($encoded);

    expect($decoded)->toBe($graph);
});

test('the encoded blob is gzip + base64, matching schema.md §3.13', function () {
    $codec = new SnapshotGraphCodec;
    $encoded = $codec->encode(['a' => 1]);

    // Valid base64 that decompresses with gzdecode — proves the on-disk
    // format is exactly "gzip + base64 of the canonical JSON graph".
    $raw = base64_decode($encoded, true);
    expect($raw)->not->toBeFalse();
    $json = gzdecode($raw);
    expect($json)->toBe('{"a":1}');
});

test('decode rejects invalid base64', function () {
    $codec = new SnapshotGraphCodec;

    expect(fn () => $codec->decode('not valid base64 !!! ###'))->toThrow(RuntimeException::class);
});
