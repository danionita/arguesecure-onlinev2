<?php

use App\Domain\Sharing\ShareTokenService;
use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Defence;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The tree editor's read route (ui.md §3). Exactly the route the legacy
 * app's unauthorized `show` endpoints lived in (security.md defect #4) —
 * every case below is a variant of "who may see this assessment", per
 * AssessmentPolicy::view()'s resolution order (is_admin -> owner ->
 * assessment_user.role -> is_public -> share token).
 */
function attachAssessmentMember(Assessment $assessment, User $user, string $role): void
{
    $assessment->members()->attach($user->id, [
        'role' => $role,
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
}

test('an anonymous user is rejected from a private assessment', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);

    $this->get(route('assessments.show', $assessment))->assertForbidden();
});

test('a logged-in stranger is rejected from a private assessment', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get(route('assessments.show', $assessment))
        ->assertForbidden();
});

test('a viewer can see the tree but its abilities are all false for mutation', function () {
    $assessment = Assessment::factory()->create();
    $viewer = User::factory()->create();
    attachAssessmentMember($assessment, $viewer, 'viewer');

    $this->actingAs($viewer)
        ->get(route('assessments.show', $assessment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Assessment/Show')
            ->where('abilities.update', false)
            ->where('abilities.manage', false)
        );
});

test('an editor can view and has update but not manage ability', function () {
    $assessment = Assessment::factory()->create();
    $editor = User::factory()->create();
    attachAssessmentMember($assessment, $editor, 'editor');

    $this->actingAs($editor)
        ->get(route('assessments.show', $assessment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('abilities.update', true)
            ->where('abilities.manage', false)
        );
});

test('the owner has every ability', function () {
    $assessment = Assessment::factory()->create();

    $this->actingAs($assessment->owner)
        ->get(route('assessments.show', $assessment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('abilities.update', true)
            ->where('abilities.manage', true)
        );
});

test('a public assessment is readable by an anonymous guest, read only', function () {
    $assessment = Assessment::factory()->public()->create();

    $this->get(route('assessments.show', $assessment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('abilities.update', false)
        );
});

test('a valid share-link session flag grants anonymous read access', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);
    $token = ShareTokenService::generate($assessment);

    $this->get('/s/'.$token)->assertRedirect(route('dashboard'));

    $this->get(route('assessments.show', $assessment))->assertOk();
});

test('the response carries the graph, computed results and node rows keyed by uuid', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Data breach']);
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Phishing']);
    $defence = Defence::factory()->create(['assessment_id' => $assessment->id, 'title' => 'MFA']);
    $risk->attacks()->attach($attack->id, ['position' => 0]);
    $attack->defences()->attach($defence->id, ['position' => 0]);

    $response = $this->actingAs($assessment->owner)
        ->get(route('assessments.show', $assessment));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Assessment/Show')
        ->has('nodes', 4) // root + risk + attack + defence
        ->has('riskAttackLinks', 1)
        ->has('attackDefenceLinks', 1)
        ->has('results.inherent')
        ->has('results.current')
        ->has('results.target')
        ->where('results.current.baseline', 'current')
    );
});

test('a soft-deleted node does not reappear in the node list', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $risk->delete();

    $response = $this->actingAs($assessment->owner)
        ->get(route('assessments.show', $assessment));

    $response->assertInertia(fn (Assert $page) => $page->has('nodes', 1)); // root only
});

/**
 * CLAUDE.md: "Eager-load everything... a whole assessment must load in four
 * queries." This controller adds exactly one query beyond
 * AssessmentGraphLoader's own four (schema.md §4) — the full node rows
 * needed for editing, which the loader's calculation-only DTO doesn't carry
 * — so the total must stay flat as the graph grows, not scale with node
 * count.
 */
test('the show route query count does not grow with the number of nodes', function () {
    // Same shape (at least one risk/attack/defence, so every branch in
    // AssessmentGraphLoader's query 2/3 actually runs) for both — only the
    // quantity differs, isolating the thing this test cares about.
    $buildAssessment = function (int $riskCount): Assessment {
        $assessment = Assessment::factory()->create();
        foreach (range(1, $riskCount) as $i) {
            $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);
            $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);
            $defence = Defence::factory()->create(['assessment_id' => $assessment->id]);
            $risk->attacks()->attach($attack->id, ['position' => 0]);
            $attack->defences()->attach($defence->id, ['position' => 0]);
        }

        return $assessment;
    };

    $small = $buildAssessment(1);
    $large = $buildAssessment(8);

    $countFor = function (Assessment $assessment): array {
        // Warm up: InstanceSettings' lazy first-row insert is a one-time
        // cost unrelated to graph size and would otherwise pollute
        // whichever assessment happens to be measured first.
        $this->actingAs($assessment->owner)->get(route('assessments.show', $assessment));

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->actingAs($assessment->owner)->get(route('assessments.show', $assessment));

        DB::flushQueryLog();

        return $queries;
    };

    $smallQueries = $countFor($small);
    $largeQueries = $countFor($large);

    expect(count($largeQueries))->toBe(count($smallQueries), implode("\n", $largeQueries)."\n---\n".implode("\n", $smallQueries));
});
