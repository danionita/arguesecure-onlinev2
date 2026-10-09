<?php

use App\Domain\Structure\LinkService;
use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Risk;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Templates (schema.md §3.14, PLAN.md Phase 8 item 4). Per CLAUDE.md's
 * W20 non-negotiables: marking an assessment as a template needs `manage`;
 * every route touching an assessment goes through AssessmentPolicy, no
 * exceptions. Creating from a template must be a genuine copy — later
 * edits to the template must never propagate into an assessment already
 * created from it — proved directly, not just asserted, below.
 */
function templateMember(Assessment $assessment, User $user, string $role): void
{
    $assessment->members()->attach($user->id, [
        'role' => $role,
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
}

// --- marking a template -------------------------------------------------

test('an anonymous user is rejected from marking an assessment as a template', function () {
    $assessment = Assessment::factory()->create();

    $this->patch(route('assessments.template.update', $assessment), ['is_template' => true])
        ->assertRedirect(route('login'));

    expect($assessment->fresh()->is_template)->toBeFalse();
});

test('an editor collaborator cannot mark an assessment as a template', function () {
    $assessment = Assessment::factory()->create();
    $editor = User::factory()->create();
    templateMember($assessment, $editor, 'editor');

    $this->actingAs($editor)
        ->patch(route('assessments.template.update', $assessment), ['is_template' => true])
        ->assertForbidden();

    expect($assessment->fresh()->is_template)->toBeFalse();
});

/*
 * W45 narrowed this route from `manage` to `saveAsTemplate` (owner or admin),
 * so this test asserts the opposite of what it did when W20 wrote it. The
 * change is deliberate and is argued in AssessmentPolicy::saveAsTemplate:
 * publishing sets `is_public` too, exposing every value in the assessment to
 * every account on the instance, which is not the same decision as inviting a
 * colleague — and unlike an invitation it cannot be taken back from whoever
 * has already read it.
 *
 * The full W45 suite lives in tests/Feature/Templates/. This one stays here so
 * the W20 file does not quietly describe a rule the application no longer has.
 */
test('a manager collaborator cannot mark an assessment as a template', function () {
    $assessment = Assessment::factory()->create();
    $manager = User::factory()->create();
    templateMember($assessment, $manager, 'manager');

    $this->actingAs($manager)
        ->patch(route('assessments.template.update', $assessment), ['is_template' => true])
        ->assertForbidden();

    expect($assessment->fresh()->is_template)->toBeFalse();
});

test('an admin can mark and unmark an assessment as a template', function () {
    $assessment = Assessment::factory()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->patch(route('assessments.template.update', $assessment), ['is_template' => true])
        ->assertRedirect();
    expect($assessment->fresh()->is_template)->toBeTrue();

    $this->actingAs($admin)
        ->patch(route('assessments.template.update', $assessment), ['is_template' => false])
        ->assertRedirect();
    expect($assessment->fresh()->is_template)->toBeFalse();
});

test('2.5.1: the owner who is not an admin can no longer publish a template', function () {
    $assessment = Assessment::factory()->create();

    $this->actingAs($assessment->owner)
        ->patch(route('assessments.template.update', $assessment), ['is_template' => true])
        ->assertForbidden();
    expect($assessment->fresh()->is_template)->toBeFalse();
});

// --- listing --------------------------------------------------------------

test('an anonymous user is redirected to login when listing templates', function () {
    $this->get(route('templates.index'))->assertRedirect(route('login'));
});

test('every signed-in account sees every template, public or not (2.5.1)', function () {
    // The bundled templates are owned by the first admin and are not public;
    // filtering on `view`, as this did through 2.5, hid them from everybody
    // else. Assessments that are not templates stay out, public or not.
    $public = Assessment::factory()->template()->public()->create();
    $private = Assessment::factory()->template()->create(['is_public' => false]);
    $notATemplate = Assessment::factory()->public()->create();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('templates.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Catalog/Templates')
            ->where('templates', fn ($templates) => collect($templates)->pluck('uuid')->contains($public->uuid)
                && collect($templates)->pluck('uuid')->contains($private->uuid)
                && ! collect($templates)->pluck('uuid')->contains($notATemplate->uuid)
            )
        );
});

// --- creating from a template -------------------------------------------

test('an anonymous user is rejected from creating an assessment from a template', function () {
    $template = Assessment::factory()->template()->public()->create();

    $this->post(route('templates.use', $template))->assertRedirect(route('login'));
});

test('any signed-in account can use a template it cannot otherwise read (2.5.1)', function () {
    $template = Assessment::factory()->template()->create(['is_public' => false]);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->post(route('templates.use', $template))
        ->assertCreated();

    // Using it grants nothing on the original: still not readable by them.
    $this->actingAs($stranger)
        ->get(route('assessments.show', $template))
        ->assertForbidden();
});

test('an assessment that is not a template 404s when used as one, even if viewable', function () {
    $assessment = Assessment::factory()->public()->create();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('templates.use', $assessment))
        ->assertNotFound();
});

/*
 * The two tests below exercise TemplateController::store(), which
 * delegates entirely to Structure\AssessmentCloner::cloneAssessment() —
 * exactly as this package's spec requires ("Creating from a template is a
 * copy... reuses Structure\AssessmentCloner unmodified"). Building
 * tests/Feature/Catalog/AssessmentClonerTest.php against that class
 * directly found that cloneAssessment() currently throws for every
 * assessment on this project's real MariaDB (STRICT_TRANS_TABLES): its
 * cloneNodes() step replicates the generated `root_marker` column without
 * excluding it, which every assessment's own root node trips over
 * immediately — see that file's docblock for the full defect writeup and
 * why this package does not patch AssessmentCloner itself (CLAUDE.md /
 * the W20 brief: "if cloning is broken, STOP and report rather than
 * fixing AssessmentCloner yourself").
 *
 * TemplateController's own logic (the view/is_template gate, the
 * response shape) is therefore proved correct up to the point where it
 * calls cloneAssessment() — the resulting 500 (an uncaught QueryException
 * rendered by the framework's own exception handler) originates entirely
 * inside AssessmentCloner, not this controller. Once that class is
 * fixed, both tests below should be restored to their intended
 * `assertCreated()` / copy-not-link assertions (kept as comments here for
 * exactly that purpose).
 */
test('creating an assessment from a template hands the caller their own independent copy', function () {
    $template = Assessment::factory()->template()->public()->create();
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->post(route('templates.use', $template))
        ->assertCreated();

    $clone = Assessment::query()->where('uuid', $response->json('assessment.uuid'))->firstOrFail();

    expect($clone->owner_user_id)->toBe($user->id)
        ->and($clone->uuid)->not->toBe($template->uuid)
        ->and($clone->is_template)->toBeFalse();
});

// --- creation is a copy, not a live link ---------------------------------

test('creating an assessment from a template is a copy, not a live link', function () {
    $template = Assessment::factory()->template()->public()->create();
    $templateRisk = Risk::factory()->for($template)->create(['title' => 'Original risk title', 'impact_amount' => 10_000]);
    $templateAttack = Attack::factory()->for($template)->create(['title' => 'Original attack title']);
    app(LinkService::class)->linkRiskToAttack($templateRisk, $templateAttack);

    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('templates.use', $template))->assertCreated();
    $clone = Assessment::query()->where('uuid', $response->json('assessment.uuid'))->firstOrFail();
    $clonedRisk = Risk::query()->where('assessment_id', $clone->id)->firstOrFail();

    // The assertion this test exists to make: cloning is a snapshot, so
    // editing the template afterwards must never reach an assessment
    // already created from it — someone signing off an assessment must not
    // find its numbers changed later by a library edit they never saw.
    $templateRisk->update(['title' => 'Edited after cloning', 'impact_amount' => 999_999]);

    expect($clonedRisk->fresh()->title)->toBe('Original risk title')
        ->and((float) $clonedRisk->fresh()->impact_amount)->toBe(10_000.0);
});
