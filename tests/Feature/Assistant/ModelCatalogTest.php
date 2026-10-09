<?php

/**
 * W94 (PLAN-2.6.md D52, D55) — the catalogue the installer and the admin tab
 * both read.
 *
 * `docker/assistant/assistant.sh` downloads and starts what
 * `docker/assistant/models.conf` lists; `ModelCatalog` reads the same file to
 * name the running model. These tests hold the file to what both readers
 * need, so an edit that the shell would accept and PHP would misread — or the
 * reverse — fails here rather than on an admin's screen.
 */

use App\Domain\Assistant\ModelCatalog;

function catalogueFile(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'models-conf-');
    file_put_contents($path, $contents);

    return $path;
}

test('the shipped catalogue offers exactly the two presets D52 chose', function () {
    $presets = app(ModelCatalog::class)->presets();

    expect(array_keys($presets))->toBe(['small', 'large']);
    expect($presets['small']['file'])->toBe('gemma-4-E4B-it-qat-UD-Q4_K_XL.gguf');
    expect($presets['large']['file'])->toBe('gemma-4-26B-A4B-it-qat-UD-Q4_K_XL.gguf');
});

test('every preset is pinned to a commit and verifiable before it is used', function () {
    foreach (app(ModelCatalog::class)->presets() as $name => $preset) {
        // D18: a revision, never `main` — `main` moves under a pinned file.
        expect($preset['revision'])->toMatch('/^[0-9a-f]{40}$/', $name);
        // The installer refuses a download whose sha256 differs; a
        // placeholder here would make it refuse every download instead.
        expect($preset['sha256'])->toMatch('/^[0-9a-f]{64}$/', $name);
        expect($preset['bytes'])->toBeGreaterThan(1_000_000_000, $name);
        expect($preset['memory_gib'])->toBeGreaterThan(0, $name);
        // The prompt cache is budgeted inside the memory figure, never beside
        // it: an operator sizing a host reads memory_gib and nothing else.
        expect($preset['cache_mib'])->toBeGreaterThan(0, $name);
        expect($preset['cache_mib'] / 1024)->toBeLessThan($preset['memory_gib'], $name);
        expect($preset['file'])->toEndWith('.gguf');
        expect($preset['label'])->not->toBe('');
    }
});

test('a model is matched to its preset by file name, wherever it is mounted', function () {
    $catalog = app(ModelCatalog::class);

    expect($catalog->forFile('/models/gemma-4-E4B-it-qat-UD-Q4_K_XL.gguf')['name'] ?? null)->toBe('small');
    expect($catalog->forFile('gemma-4-26B-A4B-it-qat-UD-Q4_K_XL.gguf')['name'] ?? null)->toBe('large');
    // A custom model, as every 2.5 deployment runs through
    // ASSISTANT_MODEL_FILE: not an error, and not a preset.
    expect($catalog->forFile('/models/gemma-3-4b-it-Q4_K_M.gguf'))->toBeNull();
});

test('a label may contain spaces, and comments and blank lines are ignored', function () {
    $catalog = new ModelCatalog(catalogueFile(<<<'CONF'
        # a comment

        tiny  t.gguf  org/repo  0123456789abcdef0123456789abcdef01234567  291545600  aaaa  1  512  A tiny model, for testing
        CONF));

    expect($catalog->presets()['tiny']['label'])->toBe('A tiny model, for testing');
    expect($catalog->presets()['tiny']['bytes'])->toBe(291545600);
    expect($catalog->presets()['tiny']['cache_mib'])->toBe(512);
});

test('a malformed catalogue fails loudly rather than hiding a preset', function () {
    // Too few columns: the shell's `read` would shift the label into the
    // cache column and leave the label empty. Better a failure than an admin
    // tab that quietly reports every model as custom.
    $catalog = new ModelCatalog(catalogueFile("tiny t.gguf org/repo rev 1 sha 1 512\n"));

    expect(fn () => $catalog->presets())->toThrow(RuntimeException::class, 'Malformed line');
});

test('a line missing its cache column is caught even when the label has spaces', function () {
    // The 2.5-shaped line, eight columns plus a multi-word label, still
    // splits into nine — with "Tiny" read as the cache size. Counting
    // columns alone would accept it; checking the numbers does not.
    $catalog = new ModelCatalog(catalogueFile("tiny t.gguf org/repo rev 1 sha 1 Tiny test model\n"));

    expect(fn () => $catalog->presets())->toThrow(RuntimeException::class, 'Malformed line');
});

test('a missing catalogue is a packaging fault, not an empty list', function () {
    expect(fn () => (new ModelCatalog('/nonexistent/models.conf'))->presets())
        ->toThrow(RuntimeException::class, 'missing');
});
