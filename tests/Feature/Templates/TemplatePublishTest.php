<?php

use App\Domain\Accounts\InstanceSettings;
use App\Models\Assessment;
use App\Models\User;

/*
 * W45 — publishing and unpublishing; reworked in 2.5.1.
 *
 * Through 2.5 publishing set `is_public` as well as `is_template`, because
 * using a template needed `view` on it — so the owner's choice to publish
 * exposed the assessment itself to the whole instance, and the instance's
 * "no public assessments" rule had to stand in front of it.
 *
 * 2.5.1 separated the two. Publishing is an admin's decision
 * (`saveAsTemplate`), every signed-in account can list and use a template
 * (`useAsTemplate`), and `is_public` is left exactly as it was — so the
 * public-assessment rule no longer applies to templates in either direction.
 */

test('publishing moves is_template and leaves is_public alone', function () {
    $assessment = Assessment::factory()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->patchJson(route('assessments.template.update', $assessment), ['is_template' => true])
        ->assertOk()
        ->assertJson(['is_template' => true, 'is_public' => false]);

    $fresh = $assessment->fresh();

    expect($fresh->is_template)->toBeTrue()
        ->and($fresh->is_public)->toBeFalse();
});

test('a published template is usable by a stranger but not readable by them', function () {
    $assessment = Assessment::factory()->create();
    $admin = User::factory()->admin()->create();
    $stranger = User::factory()->create();

    $this->actingAs($admin)
        ->patchJson(route('assessments.template.update', $assessment), ['is_template' => true])
        ->assertOk();

    $this->actingAs($stranger)->post(route('templates.use', $assessment))->assertCreated();
    $this->actingAs($stranger)->get(route('assessments.show', $assessment))->assertForbidden();
});

test('unpublishing leaves an assessment that was public still public', function () {
    $assessment = Assessment::factory()->template()->public()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->patchJson(route('assessments.template.update', $assessment), ['is_template' => false])
        ->assertOk()
        ->assertJson(['is_template' => false, 'is_public' => true]);
});

test('an unpublished template disappears from the template list', function () {
    $assessment = Assessment::factory()->template()->create();
    $admin = User::factory()->admin()->create();
    $stranger = User::factory()->create();

    $this->actingAs($admin)
        ->patchJson(route('assessments.template.update', $assessment), ['is_template' => false])
        ->assertOk();

    $this->actingAs($stranger)
        ->get(route('templates.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('templates', []));
});

test('publishing works on an instance that forbids public assessments', function () {
    InstanceSettings::update(['public_assessments_allowed' => false]);
    $assessment = Assessment::factory()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->patchJson(route('assessments.template.update', $assessment), ['is_template' => true])
        ->assertOk();

    $fresh = $assessment->fresh();

    expect($fresh->is_template)->toBeTrue()
        ->and($fresh->is_public)->toBeFalse();
});

test('the status endpoint tells the owner they may not publish, and an admin that they may', function () {
    $assessment = Assessment::factory()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($assessment->owner)
        ->getJson(route('assessments.template.show', $assessment))
        ->assertOk()
        ->assertJson(['canSaveAsTemplate' => false]);

    $this->actingAs($admin)
        ->getJson(route('assessments.template.show', $assessment))
        ->assertOk()
        ->assertJson(['canSaveAsTemplate' => true]);
});

/*
 * The route still answers an ordinary form post with a redirect, as it has
 * since W20. The Share dialog is an axios panel inside a modal that must stay
 * open, so it asks for JSON instead — two shapes, one endpoint, both pinned so
 * neither consumer can be broken silently by the other.
 */
test('a non-JSON request still gets its redirect', function () {
    $assessment = Assessment::factory()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->patch(route('assessments.template.update', $assessment), ['is_template' => true])
        ->assertRedirect(route('assessments.show', $assessment));

    expect($assessment->fresh()->is_template)->toBeTrue();
});
