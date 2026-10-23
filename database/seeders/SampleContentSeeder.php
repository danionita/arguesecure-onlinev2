<?php

namespace Database\Seeders;

use App\Models\User;
use Database\Seeders\Support\SeederFixtureException;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Seeds the reference content every instance ships with: the three system
 * scales, the bundled templates (the demo web shop, the four
 * system-archetype templates and, since 2.5.1, the four standards: ISO/IEC
 * 27001, ISO/IEC 42001, the EU AI Act and SOC 2) and the two ISO catalogue
 * libraries.
 *
 * # There is no `db:seed` any more (2.5.1)
 *
 * `DatabaseSeeder` created an administrator with a fixed e-mail address —
 * a credential in a seeder, which CLAUDE.md forbids, and a second way into a
 * fresh instance beside first-run setup. It was deleted. Everything it
 * seeded that was worth having is here, and arrives through
 * `SetupController` the moment the first administrator exists: that is the
 * earliest point an owner is available, and the only one that does not
 * require inventing a user.
 *
 * The demo web shop used to be the one fixture that was *not* a template,
 * seeded only by a deliberate `db:seed`. It is a template now, so it arrives
 * with the others and anyone can start from it.
 *
 * # Owner resolution
 *
 * Templates have no owner (W141), so the library seeds whether or not an
 * administrator exists. An owner is passed only for a fixture that is not a
 * template; none ships today, and the seeder refuses one without it.
 *
 * # Idempotency
 *
 * Delegated to the two content seeders: TemplateAssessmentSeeder skips a
 * fixture whose fixed `root_uuid` already exists as a node; CatalogLibrarySeeder
 * `firstOrCreate`s each catalogue entry by (`kind`, `title`,
 * `owner_user_id` = null). Seeding twice therefore seeds nothing the second
 * time.
 */
class SampleContentSeeder extends Seeder
{
    /**
     * Called by SetupController once the first administrator exists.
     */
    public function seedReferenceContent(?User $owner = null): void
    {
        $scales = (new SystemScaleSeeder)->run();

        [$assessmentFiles, $catalogFiles] = $this->fixtureFiles();

        $assessmentResult = (new TemplateAssessmentSeeder)->seedFiles($assessmentFiles, $owner, $scales);
        $catalogResult = (new CatalogLibrarySeeder)->seedFiles($catalogFiles);

        Log::info(sprintf(
            'SampleContentSeeder: templates seeded=%s skipped=%s; catalog fixtures seeded=%s (%d entries, %d bundle links).',
            implode(',', $assessmentResult['seeded']) ?: '-',
            implode(',', $assessmentResult['skipped']) ?: '-',
            implode(',', $catalogResult['seeded']) ?: '-',
            $catalogResult['entries'],
            $catalogResult['links'],
        ));
    }

    /**
     * The same content, for an instance whose fixtures have moved on since it
     * was set up. Needs no administrator: nothing it seeds has an owner.
     */
    public function run(): void
    {
        $this->seedReferenceContent();
    }

    /**
     * Every fixture under `database/seeders/templates`, split by `kind`
     * and sorted so the seed order is the same on every filesystem.
     *
     * @return array{0: list<string>, 1: list<string>} assessment files, catalog files
     */
    private function fixtureFiles(): array
    {
        $assessmentFiles = [];
        $catalogFiles = [];

        foreach (glob(database_path('seeders/templates').'/*.json') ?: [] as $path) {
            $decoded = $this->decode($path);

            if (! isset($decoded['kind'])) {
                throw new SeederFixtureException("Fixture file has no recognizable 'kind': {$path}");
            }

            match ($decoded['kind']) {
                'assessment' => $assessmentFiles[] = $path,
                'catalog' => $catalogFiles[] = $path,
                default => throw new SeederFixtureException("Fixture file '{$path}' has unknown kind '{$decoded['kind']}'."),
            };
        }

        sort($assessmentFiles);
        sort($catalogFiles);

        return [$assessmentFiles, $catalogFiles];
    }

    /** @return array<string, mixed> */
    private function decode(string $path): array
    {
        $raw = file_get_contents($path);
        /** @var mixed $decoded */
        $decoded = $raw !== false ? json_decode($raw, true) : null;

        if (! is_array($decoded)) {
            throw new SeederFixtureException("Fixture file is not valid JSON: {$path}");
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
