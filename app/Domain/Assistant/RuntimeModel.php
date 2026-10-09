<?php

namespace App\Domain\Assistant;

use Illuminate\Support\Facades\Cache;

/**
 * Which model the runtime says it loaded (PLAN-2.6.md D55).
 *
 * Asked of the runtime, not of `.env`. The old `assistant.model` config value
 * was a label an operator typed next to the file name; it could say one model
 * while the container served another, and nothing could tell. llama.cpp's
 * `/props` reports the path it was started with, which cannot disagree with
 * what is running, and the catalogue turns that path into a preset.
 *
 * **For the admin tab only.** It is a network call, so it is cached for
 * `assistant.health_ttl` like the health probe — a failure included, so an
 * absent runtime costs one short timeout per interval rather than one per
 * render — and nothing on an editor's render path calls it (D25).
 */
class RuntimeModel
{
    private const CACHE_KEY = 'assistant:runtime-model';

    public function __construct(
        private readonly Client $client,
        private readonly ModelCatalog $catalog,
    ) {}

    /**
     * The running model, or null when the runtime is not answering.
     *
     * @return array{file: string, preset: string|null, label: string|null, memory_gib: int|null}|null
     */
    public function current(): ?array
    {
        // Wrapped, because Cache::remember cannot tell a cached null from a
        // miss: an unreachable runtime would be re-probed on every call.
        /** @var array{model: array{file: string, preset: string|null, label: string|null, memory_gib: int|null}|null} $cached */
        $cached = Cache::remember(
            self::CACHE_KEY,
            (int) config('assistant.health_ttl'),
            fn (): array => ['model' => $this->probe()],
        );

        return $cached['model'];
    }

    /** Drops the cached answer, alongside Health::forget(). */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array{file: string, preset: string|null, label: string|null, memory_gib: int|null}|null */
    private function probe(): ?array
    {
        $response = $this->client->probe('/props');
        $path = $response?->successful() ? $response->json('model_path') : null;

        if (! is_string($path) || $path === '') {
            return null;
        }

        $preset = $this->catalog->forFile($path);

        return [
            'file' => basename($path),
            'preset' => $preset['name'] ?? null,
            'label' => $preset['label'] ?? null,
            'memory_gib' => $preset['memory_gib'] ?? null,
        ];
    }
}
