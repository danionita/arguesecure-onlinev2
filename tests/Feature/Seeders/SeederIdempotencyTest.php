<?php

use App\Models\Assessment;
use App\Models\CatalogEntry;
use App\Models\Node;
use App\Models\User;
use Database\Seeders\SampleContentSeeder;

/*
 * W22's "seeders must be idempotent — running db:seed twice must not
 * duplicate content" requirement.
 *
 * Idempotency mechanism, stated for the record (also documented on
 * SampleContentSeeder): TemplateAssessmentSeeder skips a fixture whose
 * fixed `root_uuid` already exists as a node; CatalogLibrarySeeder
 * `firstOrCreate`s each catalogue entry by (kind, title, owner_user_id =
 * null) and only attaches a bundle link that does not already exist.
 */

test('seeding sample content twice does not duplicate assessments, nodes, or catalog entries', function () {
    User::factory()->admin()->create();

    (new SampleContentSeeder)->run();

    $assessmentsAfterFirst = Assessment::count();
    $nodesAfterFirst = Node::withoutGlobalScopes()->count();
    $catalogEntriesAfterFirst = CatalogEntry::count();
    $catalogLinksAfterFirst = DB::table('catalog_entry_links')->count();

    expect($assessmentsAfterFirst)->toBeGreaterThan(0)
        ->and($nodesAfterFirst)->toBeGreaterThan(0)
        ->and($catalogEntriesAfterFirst)->toBeGreaterThan(0);

    (new SampleContentSeeder)->run();

    expect(Assessment::count())->toBe($assessmentsAfterFirst)
        ->and(Node::withoutGlobalScopes()->count())->toBe($nodesAfterFirst)
        ->and(CatalogEntry::count())->toBe($catalogEntriesAfterFirst)
        ->and(DB::table('catalog_entry_links')->count())->toBe($catalogLinksAfterFirst);
});

test('seeding twice via the real db:seed artisan command is also idempotent', function () {
    User::factory()->admin()->create();

    Artisan::call('db:seed', ['--class' => SampleContentSeeder::class, '--force' => true]);
    $assessmentsAfterFirst = Assessment::count();

    Artisan::call('db:seed', ['--class' => SampleContentSeeder::class, '--force' => true]);
    expect(Assessment::count())->toBe($assessmentsAfterFirst);
});
