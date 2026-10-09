<?php

use App\Domain\Tags\TagService;
use App\Models\Assessment;
use App\Models\Risk;
use App\Models\User;

/*
 * W45, PLAN-2.2.md D12 — "is_public grants read only, and being a template
 * does not change that. The tests are the point."
 *
 * A template is the most exposed thing this application can produce: every
 * account on the instance can read it, including every risk, attack, defence
 * and every number on them. What must not follow from that is a single write.
 *
 * Each verb is asserted separately, deliberately. A single "a stranger cannot
 * edit a template" test would go green while one route stayed open, and the
 * route it left open would be whichever one was added last — which is exactly
 * how a hole of this kind survives a review.
 */

/** The template, and someone with an account who is not on it. */
function publishedTemplate(): Assessment
{
    return Assessment::factory()->template()->public()->create();
}

// --- reading is allowed --------------------------------------------------

test('any account can read a published template', function () {
    $template = publishedTemplate();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get(route('assessments.show', $template))
        ->assertOk();
});

test('any account can create their own assessment from a published template', function () {
    $template = publishedTemplate();
    $stranger = User::factory()->create();

    $response = $this->actingAs($stranger)
        ->post(route('templates.use', $template))
        ->assertCreated();

    $clone = Assessment::query()->where('uuid', $response->json('assessment.uuid'))->firstOrFail();

    expect($clone->owner_user_id)->toBe($stranger->id)
        ->and($clone->is_template)->toBeFalse()
        // The copy is not published just because its source was. Inheriting
        // is_public here would publish someone's private working copy the
        // moment they started it from a template.
        ->and($clone->is_public)->toBeFalse();
});

// --- and nothing else is -------------------------------------------------

test('a stranger cannot PATCH a node in a template', function () {
    $template = publishedTemplate();
    $risk = Risk::factory()->for($template)->create(['title' => 'Untouched']);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->patchJson(route('assessments.nodes.update', [$template, $risk->uuid]), [
            'title' => 'Edited by a stranger',
        ])
        ->assertForbidden();

    expect($risk->fresh()->title)->toBe('Untouched');
});

test('a stranger cannot DELETE a node in a template', function () {
    $template = publishedTemplate();
    $risk = Risk::factory()->for($template)->create();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->deleteJson(route('assessments.nodes.destroy', [$template, $risk->uuid]))
        ->assertForbidden();

    expect($risk->fresh()->deleted_at)->toBeNull();
});

test('a stranger cannot add a node to a template', function () {
    $template = publishedTemplate();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->postJson(route('assessments.nodes.store', $template), [
            'type' => 'risk',
            'title' => 'Injected',
        ])
        ->assertForbidden();

    expect(Risk::query()->where('assessment_id', $template->id)->count())->toBe(0);
});

test('a stranger cannot share a template', function () {
    $template = publishedTemplate();
    $stranger = User::factory()->create();
    $accomplice = User::factory()->create();

    $this->actingAs($stranger)
        ->postJson(route('share.collaborators.store', $template), [
            'user_id' => $accomplice->id,
            'role' => 'editor',
        ])
        ->assertForbidden();

    expect($template->fresh()->members()->count())->toBe(0);
});

test('a stranger cannot generate a share link for a template', function () {
    $template = publishedTemplate();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->postJson(route('share.token.store', $template))
        ->assertForbidden();

    expect($template->fresh()->share_token)->toBeNull();
});

test('a stranger cannot delete a template', function () {
    $template = publishedTemplate();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->delete(route('assessments.destroy', $template))
        ->assertForbidden();

    expect($template->fresh())->not->toBeNull();
});

test('a stranger cannot toggle a template flag', function () {
    $template = publishedTemplate();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->patchJson(route('assessments.template.update', $template), ['is_template' => false])
        ->assertForbidden();

    expect($template->fresh()->is_template)->toBeTrue();
});

test('a stranger cannot tag a node in a template', function () {
    $template = publishedTemplate();
    $risk = Risk::factory()->for($template)->create();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->postJson(route('assessments.nodes.tags.store', [$template, $risk->uuid]), [
            'name' => 'injected',
        ])
        ->assertForbidden();

    expect($risk->fresh()->tags()->count())->toBe(0);
});

test('a stranger cannot comment on a template', function () {
    $template = publishedTemplate();
    $risk = Risk::factory()->for($template)->create();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->postJson(route('assessments.comments.store', [$template, $risk->uuid]), [
            'body' => 'Injected comment',
        ])
        ->assertForbidden();
});

test('a stranger cannot change a template’s settings', function () {
    $template = publishedTemplate();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->patch(route('assessments.settings.update', $template), [
            'title' => 'Renamed by a stranger',
            'currency' => 'USD',
            'amortization_years' => 5,
        ])
        ->assertForbidden();

    expect($template->fresh()->currency)->not->toBe('USD');
});

// --- who may publish -----------------------------------------------------

test('an editor cannot publish an assessment as a template', function () {
    $assessment = Assessment::factory()->create();
    $editor = User::factory()->create();
    $assessment->members()->attach($editor->id, [
        'role' => 'editor',
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    $this->actingAs($editor)
        ->patchJson(route('assessments.template.update', $assessment), ['is_template' => true])
        ->assertForbidden();

    expect($assessment->fresh()->is_template)->toBeFalse();
});

/*
 * The narrowing W45 introduced, and the reason it is a test rather than a
 * comment. Through W44 this route was gated on `manage`, which a `manager`
 * collaborator holds — so anyone who could invite a colleague could also
 * publish the assessment to the entire instance. Those are not the same
 * decision, and the second one cannot be taken back from whoever already read
 * it. See AssessmentPolicy::saveAsTemplate.
 */
test('a manager collaborator cannot publish an assessment as a template', function () {
    $assessment = Assessment::factory()->create();
    $manager = User::factory()->create();
    $assessment->members()->attach($manager->id, [
        'role' => 'manager',
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    $this->actingAs($manager)
        ->patchJson(route('assessments.template.update', $assessment), ['is_template' => true])
        ->assertForbidden();

    expect($assessment->fresh()->is_template)->toBeFalse()
        ->and($assessment->fresh()->is_public)->toBeFalse();
});

test('an anonymous user cannot publish an assessment as a template', function () {
    $assessment = Assessment::factory()->create();

    $this->patch(route('assessments.template.update', $assessment), ['is_template' => true])
        ->assertRedirect(route('login'));

    expect($assessment->fresh()->is_template)->toBeFalse();
});

test('an admin can publish any assessment as a template', function () {
    $assessment = Assessment::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->patchJson(route('assessments.template.update', $assessment), ['is_template' => true])
        ->assertOk();

    expect($assessment->fresh()->is_template)->toBeTrue();
});

// --- the status endpoint --------------------------------------------------

test('the template status endpoint reports state to a reader but does not offer them the control', function () {
    $template = publishedTemplate();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->getJson(route('assessments.template.show', $template))
        ->assertOk()
        ->assertJson([
            'is_template' => true,
            'is_public' => true,
            'canSaveAsTemplate' => false,
        ]);
});

test('the template status endpoint is refused to someone who cannot view the assessment', function () {
    $assessment = Assessment::factory()->create();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->getJson(route('assessments.template.show', $assessment))
        ->assertForbidden();
});

test('the owner is told they may not publish — admins only since 2.5.1', function () {
    $assessment = Assessment::factory()->create();

    $this->actingAs($assessment->owner)
        ->getJson(route('assessments.template.show', $assessment))
        ->assertOk()
        ->assertJson([
            'is_template' => false,
            'canSaveAsTemplate' => false,
        ]);
});

// --- tags survive the copy ------------------------------------------------

test('cloning a template copies its tags rather than sharing them', function () {
    $template = publishedTemplate();
    $risk = Risk::factory()->for($template)->create();
    app(TagService::class)->attach($risk, 'PCI DSS');

    $stranger = User::factory()->create();

    $response = $this->actingAs($stranger)
        ->post(route('templates.use', $template))
        ->assertCreated();

    $clone = Assessment::query()->where('uuid', $response->json('assessment.uuid'))->firstOrFail();
    $clonedRisk = Risk::query()->where('assessment_id', $clone->id)->firstOrFail();
    $clonedTag = $clonedRisk->tags()->firstOrFail();

    // Same name, different row: tags are assessment-scoped (schema.md §3.11)
    // precisely so one person's rename cannot reach anyone else's work.
    expect($clonedTag->name)->toBe('PCI DSS')
        ->and($clonedTag->assessment_id)->toBe($clone->id)
        ->and($clonedTag->id)->not->toBe($risk->tags()->firstOrFail()->id)
        ->and($clonedRisk->uuid)->not->toBe($risk->uuid);
});
