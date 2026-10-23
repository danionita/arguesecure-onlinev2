<?php

use App\Domain\Tags\TagService;
use App\Models\Assessment;
use App\Models\Risk;
use App\Models\User;

/*
 * W45, PLAN-2.2.md D12, rewritten for W141 — a template has no owner, is
 * reachable through the assessment routes by admins only, and is offered to
 * everyone else through `useAsTemplate` alone. "The tests are the point."
 *
 * Each verb is asserted separately, deliberately. A single "a stranger cannot
 * edit a template" test would go green while one route stayed open, and the
 * route it left open would be whichever one was added last.
 *
 * Nobody here is an admin except where the test says so, and a non-admin may do
 * nothing but read the library and copy from it.
 */

/** A template, public flag and stray collaborator row included on purpose. */
function publishedTemplate(): Assessment
{
    $template = Assessment::factory()->template()->public()->create();
    $template->members()->attach(User::factory()->create()->id, [
        'role' => 'manager',
        'invited_by' => null,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    return $template;
}

// --- reading the library is allowed -------------------------------------

test('any account can create their own assessment from a template', function () {
    $template = publishedTemplate();
    $stranger = User::factory()->create();

    $response = $this->actingAs($stranger)
        ->post(route('templates.use', $template))
        ->assertCreated();

    $clone = Assessment::query()->where('uuid', $response->json('assessment.uuid'))->firstOrFail();

    expect($clone->owner_user_id)->toBe($stranger->id)
        ->and($clone->is_template)->toBeFalse()
        // The copy is not published just because its source was.
        ->and($clone->is_public)->toBeFalse();
});

// --- the editor is for admins ---------------------------------------------

test('a non-admin cannot open a template in the editor, even one marked public', function () {
    $template = publishedTemplate();

    $this->actingAs(User::factory()->create())
        ->get(route('assessments.show', $template))
        ->assertForbidden();
});

test('a collaborator row on a template grants nothing', function () {
    $template = publishedTemplate();
    $manager = $template->members()->firstOrFail();

    $risk = Risk::factory()->for($template)->create(['title' => 'Untouched']);

    $this->actingAs($manager)->get(route('assessments.show', $template))->assertForbidden();
    $this->actingAs($manager)
        ->patchJson(route('assessments.nodes.update', [$template, $risk->uuid]), ['title' => 'Edited', 'updated_at' => $risk->updated_at->toJSON()])
        ->assertForbidden();
    $this->actingAs($manager)
        ->postJson(route('assessments.comments.store', [$template, $risk->uuid]), ['body' => 'Hello'])
        ->assertForbidden();
    $this->actingAs($manager)->delete(route('templates.destroy', $template))->assertForbidden();
    expect($risk->fresh()->title)->toBe('Untouched');
    $this->actingAs($manager)
        ->patchJson(route('assessments.settings.update', $template), ['currency' => 'USD', 'title' => 'x', 'amortization_years' => 3])
        ->assertForbidden();
});

test('an admin opens a template in the same editor, with no sharing', function () {
    $template = publishedTemplate();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('assessments.show', $template))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('assessment.is_template', true)
            ->where('abilities.update', true)
            ->where('abilities.transfer', false)
            ->where('abilities.publishTemplate', false));
});

// --- and nothing else is, one verb at a time ------------------------------

test('a stranger cannot PATCH a node in a template', function () {
    $template = publishedTemplate();
    $risk = Risk::factory()->for($template)->create(['title' => 'Untouched']);

    $this->actingAs(User::factory()->create())
        ->patchJson(route('assessments.nodes.update', [$template, $risk->uuid]), ['title' => 'Edited by a stranger'])
        ->assertForbidden();

    expect($risk->fresh()->title)->toBe('Untouched');
});

test('a stranger cannot DELETE a node in a template', function () {
    $template = publishedTemplate();
    $risk = Risk::factory()->for($template)->create();

    $this->actingAs(User::factory()->create())
        ->deleteJson(route('assessments.nodes.destroy', [$template, $risk->uuid]))
        ->assertForbidden();

    expect($risk->fresh()->deleted_at)->toBeNull();
});

test('a stranger cannot add a node to a template', function () {
    $template = publishedTemplate();

    $this->actingAs(User::factory()->create())
        ->postJson(route('assessments.nodes.store', $template), ['type' => 'risk', 'title' => 'Injected'])
        ->assertForbidden();

    expect(Risk::query()->where('assessment_id', $template->id)->count())->toBe(0);
});

test('a stranger cannot tag a node in a template', function () {
    $template = publishedTemplate();
    $risk = Risk::factory()->for($template)->create();

    $this->actingAs(User::factory()->create())
        ->postJson(route('assessments.nodes.tags.store', [$template, $risk->uuid]), ['name' => 'injected'])
        ->assertForbidden();

    expect($risk->fresh()->tags()->count())->toBe(0);
});

test('a stranger cannot comment on a template', function () {
    $template = publishedTemplate();
    $risk = Risk::factory()->for($template)->create();

    $this->actingAs(User::factory()->create())
        ->postJson(route('assessments.comments.store', [$template, $risk->uuid]), ['body' => 'Injected comment'])
        ->assertForbidden();
});

test('a stranger cannot change a template’s settings', function () {
    $template = publishedTemplate();

    $this->actingAs(User::factory()->create())
        ->patch(route('assessments.settings.update', $template), [
            'title' => 'Renamed by a stranger',
            'currency' => 'USD',
            'amortization_years' => 5,
        ])
        ->assertForbidden();

    expect($template->fresh()->currency)->not->toBe('USD');
});

test('a stranger cannot delete a template through either route', function () {
    $template = publishedTemplate();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->delete(route('assessments.destroy', $template))->assertStatus(404);
    $this->actingAs($stranger)->delete(route('templates.destroy', $template))->assertForbidden();

    expect($template->fresh())->not->toBeNull();
});

test('a stranger cannot publish, or publish again, a template', function () {
    $template = publishedTemplate();

    $this->actingAs(User::factory()->create())
        ->postJson(route('assessments.template.publish', $template))
        ->assertForbidden();

    expect(Assessment::query()->where('is_template', true)->count())->toBe(1);
});

// --- sharing means nothing for a template, for anyone --------------------

test('no sharing route works on a template, admins included', function (bool $admin) {
    $template = publishedTemplate();
    $user = $admin ? User::factory()->admin()->create() : User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($user);

    $this->getJson(route('share.index', $template))->assertForbidden();
    $this->getJson(route('share.users.search', $template).'?q=a')->assertForbidden();
    $this->postJson(route('share.collaborators.store', $template), ['user_id' => $other->id, 'role' => 'editor'])->assertForbidden();
    $this->patchJson(route('share.public.update', $template), ['is_public' => true])->assertForbidden();
    $this->postJson(route('share.token.store', $template))->assertForbidden();
    $this->deleteJson(route('share.token.destroy', $template))->assertForbidden();
    $this->postJson(route('share.transfer', $template), ['user_id' => $other->id])->assertForbidden();

    $fresh = $template->fresh();
    expect($fresh->share_token)->toBeNull()
        ->and($fresh->owner_user_id)->toBeNull()
        ->and($fresh->members()->count())->toBe(1);
})->with([[false], [true]]);

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
        ->postJson(route('assessments.template.publish', $assessment))
        ->assertForbidden();

    expect(Assessment::query()->where('is_template', true)->count())->toBe(0);
});

/*
 * The narrowing W45 introduced, kept: a `manager` collaborator can invite a
 * colleague but cannot offer the assessment to the whole instance, which cannot
 * be taken back from whoever already copied it. See AssessmentPolicy::saveAsTemplate.
 */
test('a manager collaborator and the owner cannot publish an assessment as a template', function () {
    $assessment = Assessment::factory()->create();
    $manager = User::factory()->create();
    $assessment->members()->attach($manager->id, [
        'role' => 'manager',
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    $this->actingAs($manager)->postJson(route('assessments.template.publish', $assessment))->assertForbidden();
    $this->actingAs($assessment->owner)->postJson(route('assessments.template.publish', $assessment))->assertForbidden();

    expect(Assessment::query()->where('is_template', true)->count())->toBe(0);
});

test('an anonymous user cannot publish an assessment as a template', function () {
    $assessment = Assessment::factory()->create();

    $this->post(route('assessments.template.publish', $assessment))->assertRedirect(route('login'));

    expect(Assessment::query()->where('is_template', true)->count())->toBe(0);
});

test('the former route that flipped a flag on the assessment is gone', function () {
    $assessment = Assessment::factory()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->patchJson('/assessments/'.$assessment->uuid.'/template', ['is_template' => true])
        ->assertStatus(405);
    $this->actingAs($admin)
        ->getJson('/assessments/'.$assessment->uuid.'/template')
        ->assertStatus(405);

    expect($assessment->fresh()->is_template)->toBeFalse();
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
