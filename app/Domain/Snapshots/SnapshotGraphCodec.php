<?php

namespace App\Domain\Snapshots;

use JsonException;
use RuntimeException;

/**
 * Encodes/decodes the `snapshots.graph` column (schema.md §3.13): "gzip +
 * base64 of the canonical JSON graph: nodes keyed by uuid, both pivots,
 * scales inlined." Pure serialization only — never touches the calculation
 * engine or Eloquent, so the same codec can decode a snapshot taken years
 * ago regardless of what the schema or scales look like today.
 *
 * The whole point of a snapshot is that it is readable byte-for-byte later
 * (CLAUDE.md §W19 brief), so this class never partially decodes or
 * "upgrades" old data in place — SnapshotService is the only place a
 * `schema_version` mismatch would ever be handled, and today there is only
 * one version.
 */
final class SnapshotGraphCodec
{
    /**
     * @param  array<string, mixed>  $graph  the canonical graph structure built by SnapshotService::buildCanonicalGraph()
     */
    public function encode(array $graph): string
    {
        try {
            $json = json_encode($graph, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Snapshot graph failed to encode as JSON: '.$e->getMessage(), previous: $e);
        }

        $gzipped = gzencode($json, 9);

        if ($gzipped === false) {
            throw new RuntimeException('Snapshot graph failed to gzip.');
        }

        return base64_encode($gzipped);
    }

    /**
     * @return array<string, mixed>
     */
    public function decode(string $blob): array
    {
        $decoded = base64_decode($blob, true);

        if ($decoded === false) {
            throw new RuntimeException('Snapshot graph is not valid base64.');
        }

        $json = gzdecode($decoded);

        if ($json === false) {
            throw new RuntimeException('Snapshot graph failed to un-gzip.');
        }

        try {
            /** @var array<string, mixed> $graph */
            $graph = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Snapshot graph failed to decode as JSON: '.$e->getMessage(), previous: $e);
        }

        return $graph;
    }
}
