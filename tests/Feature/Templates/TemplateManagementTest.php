<?php

use App\Domain\Templates\TemplateMigrator;
use App\Models\Assessment;
use App\Models\Risk;
use App\Models\User;
use Database\Seeders\SampleContentSeeder;
use Database\Seeders\SystemScaleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * W141 — templates are created, listed, edited and deleted on the Templates
 * page by admins, own nothing, and appear in no assessment list.
 */

// --- the database says what the application relies on ---------------------

test('the database refuses a template with an owner and an assessment without one', function () {
    $owner = User::factory()->create();
    $assessment = Assessment::factory()->create();

    expect(fn () => DB::table('assessments')->where('id', $assessment->id)->update(['is_template' => true]))
        ->toThrow(QueryException::class);

    $template = Assessment::factory()->template()->create();

    expect(fn () => DB::table('assessments')->where('id', $template->id)->update(['owner_user_id' => $owner->id]))
        ->toThrow(QueryException::class);
    expect(fn () => DB::table('assessments')->where('id', $template->id)->update(['is_template' => false]))
        ->toThrow(QueryException::class);
});

test('is_template cannot be mass-assigned', function () {
    $assessment = new Assessment(['is_template' => true, 'currency' => 'EUR']);

    expect($assessment->is_template)->toBeNull();
});

// --- the Templates page ---------------------------------------------------

test('the page tells an admin they can manage, and anyone else they cannot', function () {
    Assessment::factory()->template()->create();

    $this->actingAs(User::factory()->admin()->create())->get(route('templates.index'))
        ->assertInertia(fn ($page) => $page->where('canManage', true)->has('templates', 1));
    $this->actingAs(User::factory()->create())->get(route('templates.index'))
        ->assertInertia(fn ($page) => $page->where('canManage', false)->has('templates', 1)
            ->missing('templates.0.owner'));
});

test('an admin creates an empty template and lands in the editor', function () {
    (new SystemScaleSeeder)->run();
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->post(route('templates.create'));

    $template = Assessment::query()->where('is_template', true)->firstOrFail();
    $response->assertRedirect(route('assessments.show', $template));

    expect($template->owner_user_id)->toBeNull()
        ->and($template->rootNode()->firstOrFail()->title)->toBe('Untitled template')
        ->and($template->rootNode()->firstOrFail()->created_by)->toBe($admin->id);

    $this->actingAs($admin)->get(route('assessments.show', $template))->assertOk();
});

test('a non-admin cannot create a template', function () {
    $this->actingAs(User::factory()->create())->post(route('templates.create'))->assertForbidden();

    expect(Assessment::query()->where('is_template', true)->count())->toBe(0);
});

test('an admin deletes a template for good and the assessments made from it survive', function () {
    $template = Assessment::factory()->template()->create();
    Risk::factory()->for($template)->create();
    $admin = User::factory()->admin()->create();

    $copy = $this->actingAs($admin)->post(route('templates.use', $template))->json('assessment.uuid');

    $this->actingAs($admin)->delete(route('templates.destroy', $template))->assertRedirect(route('templates.index'));

    expect(Assessment::withTrashed()->find($template->id))->toBeNull()
        ->and(DB::table('nodes')->where('assessment_id', $template->id)->count())->toBe(0)
        ->and(Assessment::query()->where('uuid', $copy)->exists())->toBeTrue();
});

test('only a template can be deleted from the Templates page', function () {
    $assessment = Assessment::factory()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->delete(route('templates.destroy', $assessment))
        ->assertNotFound();

    expect($assessment->fresh())->not->toBeNull();
});

test('a template in the trash can be erased by an admin and by nobody else', function () {
    $template = Assessment::factory()->template()->create();
    $template->delete();
    $admin = User::factory()->admin()->create();

    $this->actingAs(User::factory()->create())->delete(route('assessments.erase', $template))->assertForbidden();
    expect(Assessment::withTrashed()->find($template->id))->not->toBeNull();

    $this->actingAs($admin)->delete(route('assessments.erase', $template))->assertRedirect();
    expect(Assessment::withTrashed()->find($template->id))->toBeNull();
});

test('the generic delete route refuses a template, so it is never left in the trash', function () {
    $template = Assessment::factory()->template()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->delete(route('assessments.destroy', $template))
        ->assertNotFound();

    expect($template->fresh()->trashed())->toBeFalse();
});

// --- templates are in no assessment list ----------------------------------

test('a template appears in no list, for an admin or anyone', function () {
    $template = Assessment::factory()->template()->public()->create();
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    foreach ([$admin, $user] as $person) {
        $this->actingAs($person)->get(route('assessments.index'))
            ->assertInertia(fn ($page) => $page->has('assessments', 0)->has('deleted', 0));
        $this->actingAs($person)->get(route('assessments.shared'))
            ->assertInertia(fn ($page) => $page->has('assessments', 0));
    }

    expect($template->fresh())->not->toBeNull();
});

test('deleting a user does not delete the templates', function () {
    $template = Assessment::factory()->template()->create();
    $admin = User::factory()->admin()->create();
    $victim = User::factory()->create();

    $this->actingAs($admin)->delete(route('admin.users.destroy', $victim), ['transfer_to_user_id' => $admin->id]);

    expect($template->fresh())->not->toBeNull();
});

// --- the seeder needs no admin and owns nothing ---------------------------

test('the bundled templates seed ownerless on an instance with no admin, and only once', function () {
    expect(User::query()->count())->toBe(0);

    (new SampleContentSeeder)->run();

    $count = Assessment::query()->where('is_template', true)->count();
    expect($count)->toBeGreaterThan(10)
        ->and(Assessment::query()->whereNotNull('owner_user_id')->count())->toBe(0)
        ->and(Assessment::query()->where('is_public', true)->count())->toBe(0);

    (new SampleContentSeeder)->run();

    expect(Assessment::query()->where('is_template', true)->count())->toBe($count);
});

test('an edited bundled template is not overwritten by the next seed', function () {
    (new SampleContentSeeder)->run();
    $template = Assessment::query()->where('is_template', true)->firstOrFail();
    $template->rootNode()->firstOrFail()->update(['title' => 'Edited by an admin']);

    (new SampleContentSeeder)->run();

    expect($template->rootNode()->firstOrFail()->fresh()->title)->toBe('Edited by an admin');
});

// --- migrating what 2.6 left behind (T6) -----------------------------------

/** Drops the CHECK so a pre-W141 shape can be built, and puts it back after. */
function withoutTemplateCheck(Closure $body): void
{
    DB::statement('ALTER TABLE assessments DROP CONSTRAINT assessments_template_ownerless');
    try {
        $body();
    } finally {
        DB::statement('ALTER TABLE assessments ADD CONSTRAINT assessments_template_ownerless CHECK ((is_template = 1) = (owner_user_id IS NULL))');
    }
}

test('a bundled template owned by an admin is converted in place', function () {
    (new SampleContentSeeder)->run();
    $bundled = Assessment::query()->where('is_template', true)->firstOrFail();
    $bundledRoot = $bundled->rootNode()->firstOrFail()->uuid;
    $nodes = $bundled->nodes()->count();
    $admin = User::factory()->admin()->create();
    $collaborator = User::factory()->create();

    withoutTemplateCheck(function () use ($bundled, $admin, $collaborator): void {
        DB::table('assessments')->where('id', $bundled->id)->update([
            'owner_user_id' => $admin->id, 'is_public' => true, 'share_token' => str_repeat('a', 40),
            'share_token_created_at' => now(),
        ]);
        $bundled->members()->attach($collaborator->id, [
            'role' => 'editor', 'invited_by' => $admin->id, 'invited_at' => now(), 'accepted_at' => now(),
        ]);

        expect(app(TemplateMigrator::class)->run())->toBe(['converted' => 1, 'copied' => 0, 'unflagged' => 0]);
    });

    $fresh = $bundled->fresh();
    expect($fresh->owner_user_id)->toBeNull()
        ->and($fresh->is_template)->toBeTrue()
        ->and($fresh->is_public)->toBeFalse()
        ->and($fresh->share_token)->toBeNull()
        ->and($fresh->members()->count())->toBe(0)
        ->and($fresh->id)->toBe($bundled->id)
        ->and($fresh->rootNode()->firstOrFail()->uuid)->toBe($bundledRoot)
        ->and($fresh->nodes()->count())->toBe($nodes);
});

test('a template somebody published is copied and the assessment goes back to its owner', function () {
    $owner = User::factory()->create();
    $collaborator = User::factory()->create();
    $source = Assessment::factory()->create(['owner_user_id' => $owner->id]);
    $risk = Risk::factory()->for($source)->create(['title' => 'Kept']);
    $source->members()->attach($collaborator->id, [
        'role' => 'editor', 'invited_by' => $owner->id, 'invited_at' => now(), 'accepted_at' => now(),
    ]);

    withoutTemplateCheck(function () use ($source): void {
        DB::table('assessments')->where('id', $source->id)->update(['is_template' => true, 'is_public' => true]);

        expect(app(TemplateMigrator::class)->run())->toBe(['converted' => 0, 'copied' => 1, 'unflagged' => 0]);
        // Rerunning changes nothing: the original is no longer a template.
        expect(app(TemplateMigrator::class)->run())->toBe(['converted' => 0, 'copied' => 0, 'unflagged' => 0]);
    });

    $original = $source->fresh();
    expect($original->is_template)->toBeFalse()
        ->and($original->owner_user_id)->toBe($owner->id)
        ->and($original->members()->count())->toBe(1)
        ->and(Risk::query()->where('assessment_id', $original->id)->count())->toBe(1);

    $copy = Assessment::query()->where('is_template', true)->firstOrFail();
    expect($copy->id)->not->toBe($source->id)
        ->and($copy->owner_user_id)->toBeNull()
        ->and($copy->members()->count())->toBe(0)
        ->and(Risk::query()->where('assessment_id', $copy->id)->pluck('title')->all())->toBe(['Kept'])
        ->and(Risk::query()->where('assessment_id', $copy->id)->value('uuid'))->not->toBe($risk->uuid);
});

// --- the library's changes are in the security log (W141) -------------------

test('creating, publishing and deleting a template are logged, with the actor and the template', function () {
    require_once __DIR__.'/../Audit/Capture.php';
    (new SystemScaleSeeder)->run();
    $admin = User::factory()->admin()->create();
    $source = Assessment::factory()->create();
    $log = captureSecurityLog();

    $this->actingAs($admin)->post(route('templates.create'));
    $created = Assessment::query()->where('is_template', true)->firstOrFail();
    $published = $this->actingAs($admin)->postJson(route('assessments.template.publish', $source))->json('template.uuid');
    $this->actingAs($admin)->delete(route('templates.destroy', $created));

    $line = securityLine($log, 'template.created');
    expect($line['actor']['id'])->toBe($admin->id)
        ->and($line['target'])->toBe(['type' => 'assessment', 'id' => $created->uuid]);

    $line = securityLine($log, 'template.published');
    expect($line['target']['id'])->toBe($published)
        ->and($line['details']['source'] ?? $line['source'] ?? null)->toBe($source->uuid);

    $line = securityLine($log, 'template.deleted');
    expect($line['target']['id'])->toBe($created->uuid);
});
