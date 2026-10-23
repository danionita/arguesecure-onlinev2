<?php

use App\Domain\Sharing\ShareTokenService;
use App\Models\Assessment;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The report route is exactly as visible as the tree editor's read route
 * (AssessmentShowTest.php's own docblock): a variant of "who may see this
 * assessment" over AssessmentPolicy::view()'s resolution order (is_admin ->
 * owner -> assessment_user.role -> is_public -> share token). This is the
 * class of route legacy defect #4 lived in (security.md) — every case below
 * asserts a stranger with no share gets 403, on all three W17 routes
 * (report page, CSV export, JSON export), per the W17 task brief's explicit
 * requirement.
 */
function reportMember(Assessment $assessment, User $user, string $role): void
{
    $assessment->members()->attach($user->id, [
        'role' => $role,
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
}

test('an anonymous user is rejected from the report of a private assessment', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);

    $this->get(route('assessments.report.show', $assessment))->assertForbidden();
});

test('a logged-in stranger with no share is rejected from the report page', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get(route('assessments.report.show', $assessment))
        ->assertForbidden();
});

test('a logged-in stranger with no share is rejected from the defences CSV export', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get(route('assessments.report.defences-csv', $assessment))
        ->assertForbidden();
});

test('a logged-in stranger with no share is rejected from the JSON export', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get(route('assessments.report.export-json', $assessment))
        ->assertForbidden();
});

test('an anonymous guest is rejected from all three routes of a private assessment', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);

    $this->get(route('assessments.report.show', $assessment))->assertForbidden();
    $this->get(route('assessments.report.defences-csv', $assessment))->assertForbidden();
    $this->get(route('assessments.report.export-json', $assessment))->assertForbidden();
});

test('the owner can view the report', function () {
    $assessment = Assessment::factory()->create();

    $this->actingAs($assessment->owner)
        ->get(route('assessments.report.show', $assessment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Report/Show'));
});

test('a viewer collaborator can view the report', function () {
    $assessment = Assessment::factory()->create();
    $viewer = User::factory()->create();
    reportMember($assessment, $viewer, 'viewer');

    $this->actingAs($viewer)
        ->get(route('assessments.report.show', $assessment))
        ->assertOk();
});

test('an admin can view the report of any assessment', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->get(route('assessments.report.show', $assessment))
        ->assertOk();
});

test('a public assessment report is readable by an anonymous guest', function () {
    $assessment = Assessment::factory()->public()->create();

    $this->get(route('assessments.report.show', $assessment))->assertOk();
    $this->get(route('assessments.report.defences-csv', $assessment))->assertOk();
    $this->get(route('assessments.report.export-json', $assessment))->assertOk();
});

test('a valid share-link session flag grants anonymous read access to the report', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);
    $token = ShareTokenService::generate($assessment);

    $this->get('/s/'.$token)->assertRedirect(route('assessments.show', $assessment));

    $this->get(route('assessments.report.show', $assessment))->assertOk();
});

/*
 * `view` is the broadest ability in the system: is_public grants it to the
 * whole internet and a share link grants it to anyone holding the URL
 * (security.md §3.1). Anything the report serialises is therefore published
 * to that audience, whether or not a component renders it — Inertia ships
 * the entire prop bag in the page's data attribute, so an unrendered field
 * is still readable with View Source. The owner's address in particular
 * would turn every public report into a harvestable email; collaborator
 * emails belong behind `manage` in the share dialog, and nowhere else.
 */
test('no report surface exposes the owner email to a public reader', function () {
    $assessment = Assessment::factory()->public()->create();
    $email = $assessment->owner->email;

    $this->get(route('assessments.report.show', $assessment))
        ->assertOk()
        ->assertDontSee($email, escape: false);

    $this->get(route('assessments.report.export-json', $assessment))
        ->assertOk()
        ->assertDontSee($email, escape: false);

    $this->get(route('assessments.report.defences-csv', $assessment))
        ->assertOk()
        ->assertDontSee($email, escape: false);
});

/*
 * W83, D40/D41. AssessmentActions.vue gates Settings and Share on
 * abilities.manage — ReportController resolves it once, the same
 * AssessmentPolicy::manage() the editor already reads off store.abilities
 * (assessmentStore.ts). A viewer collaborator has `view` but not `manage`
 * (AssessmentPolicy::ROLE_ABILITIES); the owner always has it.
 */
test('abilities.manage is true for the owner', function () {
    $assessment = Assessment::factory()->create();

    $this->actingAs($assessment->owner)
        ->get(route('assessments.report.show', $assessment))
        ->assertInertia(fn (Assert $page) => $page->where('abilities.manage', true));
});

test('abilities.manage is false for a viewer collaborator', function () {
    $assessment = Assessment::factory()->create();
    $viewer = User::factory()->create();
    reportMember($assessment, $viewer, 'viewer');

    $this->actingAs($viewer)
        ->get(route('assessments.report.show', $assessment))
        ->assertInertia(fn (Assert $page) => $page->where('abilities.manage', false));
});

test('abilities.manage is false for an anonymous guest reading a public report', function () {
    $assessment = Assessment::factory()->public()->create();

    $this->get(route('assessments.report.show', $assessment))
        ->assertInertia(fn (Assert $page) => $page->where('abilities.manage', false));
});

test('the CSV export streams a text/csv response with the expected filename', function () {
    $assessment = Assessment::factory()->create();

    $response = $this->actingAs($assessment->owner)
        ->get(route('assessments.report.defences-csv', $assessment));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/csv');
    expect($response->headers->get('Content-Disposition'))->toContain('defences-'.$assessment->uuid.'.csv');
});

test('the JSON export streams a downloadable json response', function () {
    $assessment = Assessment::factory()->create();

    $response = $this->actingAs($assessment->owner)
        ->get(route('assessments.report.export-json', $assessment));

    $response->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain('assessment-'.$assessment->uuid.'.json');
    $response->assertJsonStructure(['schemaVersion', 'assessment', 'nodes', 'results' => ['inherent', 'current', 'target']]);
});
