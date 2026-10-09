<?php

namespace App\Domain\Assistant;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * The assistant's model presets (PLAN-2.6.md D52), read from
 * `docker/assistant/models.conf`.
 *
 * **The same file the installer reads.** `docker/assistant/assistant.sh`
 * downloads and starts what this file lists; this class tells an admin which
 * of those is running. Two readers of one file is the point: a preset cannot
 * mean one thing to the installer and another to the admin page, because
 * there is nowhere for the two definitions to drift apart.
 *
 * The format is the shell's, not PHP's — whitespace-separated columns, the
 * label last and free to contain spaces, `#` comments — because the shell is
 * the reader with no parser to lean on.
 *
 * @phpstan-type Preset array{
 *     name: string, file: string, repo: string, revision: string,
 *     bytes: int, sha256: string, memory_gib: int, cache_mib: int, label: string
 * }
 */
class ModelCatalog
{
    /** @var array<string, Preset>|null */
    private ?array $presets = null;

    public function __construct(private readonly ?string $path = null) {}

    /** @return array<string, Preset> keyed by preset name, in file order */
    public function presets(): array
    {
        return $this->presets ??= $this->parse();
    }

    /**
     * The preset whose file this is, by file name alone.
     *
     * Matched on the name because that is what the runtime reports
     * (`/props` → `model_path`). The installer only ever writes a preset's
     * file under its catalogue name, after checking its sha256, so a name
     * match is an install match; a file dropped in by hand under a preset's
     * name is the operator's own doing, and `serve` refuses it at start if its
     * size is wrong.
     *
     * @return Preset|null
     */
    public function forFile(string $path): ?array
    {
        $file = basename($path);

        foreach ($this->presets() as $preset) {
            if ($preset['file'] === $file) {
                return $preset;
            }
        }

        return null;
    }

    /** @return array<string, Preset> */
    private function parse(): array
    {
        $path = $this->path ?? base_path('docker/assistant/models.conf');

        if (! File::exists($path)) {
            // A packaging fault, not a runtime state: the file ships with the
            // application and the installer beside it. Failing loudly beats an
            // admin page that quietly shows every model as "custom".
            throw new RuntimeException("The assistant model catalogue is missing: {$path}");
        }

        $presets = [];

        foreach (preg_split('/\R/', File::get($path)) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $columns = preg_split('/\s+/', $line, 9);

            // Numeric columns are checked as numbers, not just counted: a
            // line missing one column still splits into nine when its label
            // has a space in it, and the shell would then read a word of the
            // label as a size.
            if ($columns === false || count($columns) !== 9
                || ! ctype_digit($columns[4]) || ! ctype_digit($columns[6]) || ! ctype_digit($columns[7])) {
                throw new RuntimeException("Malformed line in the assistant model catalogue: {$line}");
            }

            [$name, $file, $repo, $revision, $bytes, $sha256, $memory, $cache, $label] = $columns;

            $presets[$name] = [
                'name' => $name,
                'file' => $file,
                'repo' => $repo,
                'revision' => $revision,
                'bytes' => (int) $bytes,
                'sha256' => $sha256,
                'memory_gib' => (int) $memory,
                'cache_mib' => (int) $cache,
                'label' => $label,
            ];
        }

        return $presets;
    }
}
