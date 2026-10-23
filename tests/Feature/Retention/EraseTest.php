<?php

use App\Domain\Notifications\NodeAssigned;
use App\Domain\Retention\AssessmentEraser;
use App\Domain\Retention\TrashPurger;
use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Comment;
use App\Models\Defence;
use App\Models\NodeReference;
use App\Models\Risk;
use App\Models\Scale;
use App\Models\ScaleLevel;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\SystemScaleSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * W113 — erasing an assessment, and the daily purge that does it on a timer.
 *
 * The acceptance criterion is that erasing leaves no row referencing the
 * assessment in any table, *enumerated from the schema rather than listed by
 * hand*: `rowsReachableFrom()` walks every foreign key information_schema
 * reports, outward from the assessment, so a table a later package adds
 * with a key into this graph is covered without anyone editing this file —
 * and `fullyPopulatedAssessment()` must then put a row in it, or the
 * coverage assertion below names the edge it is missing.
 */
beforeEach(function () {
    (new SystemScaleSeeder)->run();
});

/**
 * Every foreign key in the schema: [child table, child column, parent table].
 *
 * @return list<array{0: string, 1: string, 2: string}>
 */
function retentionForeignKeys(): array
{
    return collect(DB::select(
        'SELECT TABLE_NAME AS t, COLUMN_NAME AS c, REFERENCED_TABLE_NAME AS p
           FROM information_schema.KEY_COLUMN_USAGE
          WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL'
    ))->map(fn ($row) => [(string) $row->t, (string) $row->c, (string) $row->p])->values()->all();
}

/**
 * Breadth-first from one assessment row along every foreign key that points
 * *into* what has been reached: the rows that exist because the assessment
 * does. `users` is never entered — an account referencing nothing of the
 * assessment's is not part of it — and a row reached twice is walked once.
 *
 * @return array{ids: array<string, list<int>>, edges: array<string, int>}
 *                                                                         ids per table, and the number of rows found along each `child.column -> parent` edge
 */
function rowsReachableFrom(int $assessmentId): array
{
    $keys = retentionForeignKeys();
    $ids = ['assessments' => [$assessmentId]];
    $edges = [];
    $queue = ['assessments'];

    while ($queue !== []) {
        $parent = array_shift($queue);

        foreach ($keys as [$child, $column, $referenced]) {
            if ($referenced !== $parent || $child === 'users') {
                continue;
            }

            $rows = DB::table($child)->whereIn($column, $ids[$parent])->get();
            $edges["{$child}.{$column} -> {$parent}"] = ($edges["{$child}.{$column} -> {$parent}"] ?? 0) + $rows->count();

            if (! Schema::hasColumn($child, 'id')) {
                continue;
            }

            $new = $rows->pluck('id')->map(fn ($id) => (int) $id)
                ->diff($ids[$child] ?? [])->values()->all();

            if ($new !== []) {
                $ids[$child] = array_values(array_merge($ids[$child] ?? [], $new));
                $queue[] = $child;
            }
        }
    }

    return ['ids' => $ids, 'edges' => $edges];
}

/**
 * An assessment with a row behind every foreign key that can reach it:
 * calibrated scales of its own (which its nodes and strengths point at), the
 * full graph, labels, references, assignees, a threaded comment, history
 * tied to an operation, a snapshot, a member — and a notification, which no
 * foreign key reaches.
 */
function fullyPopulatedAssessment(User $owner): Assessment
{
    $assessment = Assessment::factory()->create(['owner_user_id' => $owner->id]);

    $levels = [];
    foreach (['impact', 'likelihood', 'strength'] as $kind) {
        $scale = Scale::factory()->forAssessment($assessment->id)->create([
            'kind' => $kind,
            'unit' => match ($kind) {
                'impact' => 'currency',
                'likelihood' => 'rate_per_year',
                'strength' => 'fraction',
            },
        ]);
        $levels[$kind] = ScaleLevel::factory()->create(['scale_id' => $scale->id]);
        $assessment->{"{$kind}_scale_id"} = $scale->id;
    }
    $assessment->save();

    $risk = Risk::factory()->create([
        'assessment_id' => $assessment->id,
        'impact_mode' => 'qualitative',
        'impact_amount' => null,
        'impact_level_id' => $levels['impact']->id,
    ]);
    $attack = Attack::factory()->create([
        'assessment_id' => $assessment->id,
        'likelihood_mode' => 'qualitative',
        'likelihood_aro' => null,
        'likelihood_level_id' => $levels['likelihood']->id,
    ]);
    $defence = Defence::factory()->create(['assessment_id' => $assessment->id]);

    DB::table('risk_attack')->insert([
        'risk_node_id' => $risk->id, 'attack_node_id' => $attack->id, 'position' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('attack_defence')->insert([
        'attack_node_id' => $attack->id, 'defence_node_id' => $defence->id, 'position' => 0,
        'strength_mode' => 'qualitative', 'strength_level_id' => $levels['strength']->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $tag = Tag::factory()->create(['assessment_id' => $assessment->id]);
    DB::table('node_tag')->insert(['node_id' => $risk->id, 'tag_id' => $tag->id]);
    NodeReference::factory()->create(['node_id' => $attack->id]);

    $member = User::factory()->create();
    $assessment->members()->attach($member->id, [
        'role' => 'editor', 'invited_by' => $owner->id, 'invited_at' => now(), 'accepted_at' => now(),
    ]);
    DB::table('node_assignees')->insert([
        'node_id' => $defence->id, 'user_id' => $member->id,
    ]);

    $comment = Comment::factory()->create([
        'assessment_id' => $assessment->id, 'node_id' => $risk->id, 'user_id' => $owner->id,
    ]);
    Comment::factory()->create([
        'assessment_id' => $assessment->id, 'node_id' => $risk->id, 'user_id' => $member->id,
        'parent_comment_id' => $comment->id,
    ]);

    $operationId = DB::table('assessment_operations')->insertGetId([
        'assessment_id' => $assessment->id, 'user_id' => $owner->id, 'kind' => 'node.create',
        'summary' => 'Added a risk', 'inverse' => json_encode(['action' => 'delete_node']), 'created_at' => now(),
    ]);
    DB::table('node_revisions')->insert([
        'assessment_id' => $assessment->id, 'node_id' => $risk->id, 'node_uuid' => $risk->uuid,
        'user_id' => $owner->id, 'action' => 'create', 'changes' => json_encode(['title' => [null, $risk->title]]),
        'operation_id' => $operationId, 'created_at' => now(),
    ]);
    DB::table('snapshots')->insert([
        'assessment_id' => $assessment->id, 'name' => 'Baseline', 'created_by' => $owner->id,
        'graph' => '{}', 'results' => '{}', 'schema_version' => 2, 'created_at' => now(),
    ]);

    $member->notify(new NodeAssigned($defence, $owner));

    return $assessment->fresh();
}

function notificationsAbout(Assessment $assessment): int
{
    return DB::table('notifications')->where('data', 'like', '%'.$assessment->uuid.'%')->count();
}

test('erasing leaves no row referencing the assessment in any table, walked from the schema', function () {
    $owner = User::factory()->create();
    $assessment = fullyPopulatedAssessment($owner);
    $bystander = fullyPopulatedAssessment($owner);

    $before = rowsReachableFrom($assessment->id);
    $bystanderBefore = rowsReachableFrom($bystander->id);

    // The walk is only as good as the data it walks: every foreign key that
    // reaches the assessment must have carried a row, or a table could be
    // left behind without this test noticing. A new edge with zero rows is a
    // table this fixture has to learn to fill.
    expect(collect($before['edges'])->filter(fn (int $rows) => $rows === 0)->keys()->all())->toBe([]);
    // A floor on the walk itself, so a schema query that silently returns
    // nothing cannot pass: these tables are named by schema.md's cascade.
    expect(array_keys($before['ids']))->toContain(
        'nodes', 'scales', 'scale_levels', 'risk_attack', 'attack_defence', 'tags',
        'node_references', 'comments', 'assessment_operations', 'node_revisions', 'snapshots',
        'assessment_user',
    );
    expect(notificationsAbout($assessment))->toBe(1);

    $assessment->delete();
    app(AssessmentEraser::class)->erase(Assessment::withTrashed()->findOrFail($assessment->id));

    // Every row the walk reached is gone, table by table — including the
    // id-less pivots, which the walk reaches by their foreign key values.
    foreach ($before['ids'] as $table => $ids) {
        expect(DB::table($table)->whereIn('id', $ids)->count())->toBe(0, "{$table} kept rows");
    }
    foreach (retentionForeignKeys() as [$child, $column, $parent]) {
        if (isset($before['ids'][$parent]) && $child !== 'users') {
            expect(DB::table($child)->whereIn($column, $before['ids'][$parent])->count())
                ->toBe(0, "{$child}.{$column} still references an erased {$parent} row");
        }
    }

    // History rows whose node_id a cascade would have nulled are deleted,
    // not left behind as anonymous rows (PLAN-2.7 W113).
    expect(DB::table('node_revisions')->whereNull('node_id')->count())->toBe(0);
    expect(notificationsAbout($assessment))->toBe(0);

    // And nothing of the other assessment went with it.
    expect(rowsReachableFrom($bystander->id))->toEqual($bystanderBefore);
    expect(notificationsAbout($bystander))->toBe(1);
    // The users stay: an account is not part of an assessment.
    expect(User::query()->whereKey($owner->id)->exists())->toBeTrue();
});

test('a plain hard delete of an assessment with its own scales is refused by the schema, which is why the eraser exists', function () {
    // The cycle AssessmentEraser's docblock describes: the assessment owns
    // its scales (cascading) and references them (RESTRICT). Kept as a test
    // so that if a migration ever breaks the cycle, the repointing step can
    // be dropped rather than carried forever.
    $assessment = fullyPopulatedAssessment(User::factory()->create());

    expect(fn () => DB::table('assessments')->where('id', $assessment->id)->delete())
        ->toThrow(QueryException::class);
    expect(Assessment::query()->whereKey($assessment->id)->exists())->toBeTrue();
});

test('the purge erases exactly what was deleted longer ago than the retention period', function () {
    config(['arguesecure.trash_retention_days' => 30]);
    $owner = User::factory()->create();

    $old = fullyPopulatedAssessment($owner);
    $recent = fullyPopulatedAssessment($owner);
    $live = fullyPopulatedAssessment($owner);

    $now = now();
    // A minute either side of the line: thirty days and a minute ago is
    // erased, thirty days less a minute is kept.
    $old->deleted_at = $now->copy()->subDays(30)->subMinute();
    $old->save();
    $recent->deleted_at = $now->copy()->subDays(30)->addMinute();
    $recent->save();

    expect(app(TrashPurger::class)->purge($now))->toBe(1);

    expect(Assessment::withTrashed()->whereKey($old->id)->exists())->toBeFalse();
    expect(DB::table('nodes')->where('assessment_id', $old->id)->count())->toBe(0);

    expect(Assessment::withTrashed()->whereKey($recent->id)->exists())->toBeTrue();
    expect(DB::table('nodes')->where('assessment_id', $recent->id)->count())->toBe(4);
    expect(Assessment::query()->whereKey($live->id)->exists())->toBeTrue();
});

test('the purge reads the retention period from the setting', function () {
    config(['arguesecure.trash_retention_days' => 7]);
    $assessment = Assessment::factory()->create();
    $assessment->deleted_at = now()->subDays(8);
    $assessment->save();

    expect(app(TrashPurger::class)->purge())->toBe(1);
    expect(Assessment::withTrashed()->whereKey($assessment->id)->exists())->toBeFalse();
});

test('the purge never touches a live assessment, however old', function () {
    $assessment = Assessment::factory()->create();
    DB::table('assessments')->where('id', $assessment->id)->update(['created_at' => now()->subYears(3), 'updated_at' => now()->subYears(3)]);

    expect(app(TrashPurger::class)->purge())->toBe(0);
    expect(Assessment::query()->whereKey($assessment->id)->exists())->toBeTrue();
});

test('the purge runs daily from the scheduler', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('arguesecure:purge-deleted')
        ->assertSuccessful();

    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'arguesecure:purge-deleted'));

    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('0 0 * * *');
});

test('the purge command reports how many it erased', function () {
    $assessment = Assessment::factory()->create();
    $assessment->deleted_at = now()->subDays(31);
    $assessment->save();

    $this->artisan('arguesecure:purge-deleted')
        ->expectsOutput('Erased 1 deleted assessment(s).')
        ->assertSuccessful();
});

test('a restore that wins the race is not undone by the erase', function () {
    $assessment = fullyPopulatedAssessment(User::factory()->create());
    $assessment->delete();
    $stale = Assessment::withTrashed()->findOrFail($assessment->id);
    Assessment::withTrashed()->findOrFail($assessment->id)->restore();

    expect(app(AssessmentEraser::class)->erase($stale))->toBeFalse();
    expect(Assessment::query()->whereKey($assessment->id)->exists())->toBeTrue();
});

test('an assessment with no scales of its own is erased even where no system scale exists', function () {
    $assessment = Assessment::factory()->create();
    $assessment->delete();
    // Detach it from the system scales is impossible (NOT NULL), so the
    // claim is narrower: the repoint step is skipped when nothing is owned.
    DB::enableQueryLog();
    app(AssessmentEraser::class)->erase(Assessment::withTrashed()->findOrFail($assessment->id));
    $updates = collect(DB::getQueryLog())->filter(fn ($q) => str_starts_with($q['query'], 'update `assessments`'));
    DB::disableQueryLog();

    expect($updates)->toBeEmpty();
    expect(Assessment::withTrashed()->whereKey($assessment->id)->exists())->toBeFalse();
});

test('a blank or non-numeric retention setting falls back to 30 days, not to the floor', function () {
    foreach (['' => 30, 'thirty' => 30, '0' => 1, '-5' => 1, '7' => 7] as $value => $expected) {
        // A .env that defines the key (CI copies .env.example) loads it into $_SERVER too,
        // and env() reads that copy before the others.
        putenv('TRASH_RETENTION_DAYS='.$value);
        $_ENV['TRASH_RETENTION_DAYS'] = $_SERVER['TRASH_RETENTION_DAYS'] = $value;
        $config = require config_path('arguesecure.php');
        expect($config['trash_retention_days'])->toBe($expected, "TRASH_RETENTION_DAYS={$value}");
    }
    putenv('TRASH_RETENTION_DAYS');
    unset($_ENV['TRASH_RETENTION_DAYS'], $_SERVER['TRASH_RETENTION_DAYS']);
});
