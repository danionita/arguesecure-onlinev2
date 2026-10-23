<?php

use App\Domain\Structure\LinkService;
use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Defence;
use App\Models\Risk;
use App\Models\User;

/*
 * W141, T3 — publishing copies. The template is a separate, ownerless
 * assessment; the source is neither flagged nor linked nor touched, and there
 * is nothing to unpublish. Through 2.6 publishing flipped `is_template` on the
 * assessment itself, so these tests replace the ones that asserted that.
 */

/** A small assessment with one of each node and both edges. */
function sourceAssessment(): Assessment
{
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create(['title' => 'Data breach']);
    $attack = Attack::factory()->for($assessment)->create(['title' => 'Phishing']);
    $defence = Defence::factory()->for($assessment)->create(['title' => 'MFA']);
    app(LinkService::class)->linkRiskToAttack($risk, $attack);
    app(LinkService::class)->linkAttackToDefence($attack, $defence);

    return $assessment;
}

test('an admin publishes a copy: one ownerless template with the same shape and new uuids', function () {
    $source = sourceAssessment();
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)
        ->postJson(route('assessments.template.publish', $source))
        ->assertCreated();

    $template = Assessment::query()->where('uuid', $response->json('template.uuid'))->firstOrFail();

    expect($template->is_template)->toBeTrue()
        ->and($template->owner_user_id)->toBeNull()
        ->and($template->is_public)->toBeFalse()
        ->and($template->share_token)->toBeNull()
        ->and($template->id)->not->toBe($source->id)
        ->and(Assessment::query()->where('is_template', true)->count())->toBe(1);

    $titles = fn (Assessment $a) => $a->nodes()->orderBy('type')->pluck('title')->all();
    expect($titles($template))->toBe($titles($source));
    expect($template->nodes()->pluck('uuid')->intersect($source->nodes()->pluck('uuid')))->toBeEmpty();
    expect($template->nodes()->count())->toBe(4);
    expect(DB::table('risk_attack')->whereIn('risk_node_id', $template->nodes()->pluck('id'))->count())->toBe(1)
        ->and(DB::table('attack_defence')->whereIn('attack_node_id', $template->nodes()->pluck('id'))->count())->toBe(1);
});

test('the source is left exactly as it was', function () {
    $source = sourceAssessment();
    $before = $source->fresh()->getAttributes();
    $nodesBefore = DB::table('nodes')->where('assessment_id', $source->id)->orderBy('id')->get()->all();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->postJson(route('assessments.template.publish', $source))->assertCreated();

    expect($source->fresh()->getAttributes())->toBe($before)
        ->and($source->fresh()->is_template)->toBeFalse()
        ->and(DB::table('nodes')->where('assessment_id', $source->id)->orderBy('id')->get()->all())->toEqual($nodesBefore);
});

test('editing the source afterwards does not change the template, and the reverse', function () {
    $source = sourceAssessment();
    $admin = User::factory()->admin()->create();

    $uuid = $this->actingAs($admin)->postJson(route('assessments.template.publish', $source))->json('template.uuid');
    $template = Assessment::query()->where('uuid', $uuid)->firstOrFail();

    $sourceRisk = Risk::query()->where('assessment_id', $source->id)->firstOrFail();
    $templateRisk = Risk::query()->where('assessment_id', $template->id)->firstOrFail();

    $this->actingAs($admin)
        ->patchJson(route('assessments.nodes.update', [$source, $sourceRisk->uuid]), ['title' => 'Renamed in the source', 'updated_at' => $sourceRisk->updated_at->toJSON()])
        ->assertOk();
    $this->actingAs($admin)
        ->patchJson(route('assessments.nodes.update', [$template, $templateRisk->uuid]), ['title' => 'Renamed in the template', 'updated_at' => $templateRisk->updated_at->toJSON()])
        ->assertOk();

    expect($templateRisk->fresh()->title)->toBe('Renamed in the template')
        ->and($sourceRisk->fresh()->title)->toBe('Renamed in the source');
});

test('the copy carries none of the source’s people', function () {
    $source = sourceAssessment();
    $source->members()->attach(User::factory()->create()->id, [
        'role' => 'editor',
        'invited_by' => $source->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $source->forceFill(['is_public' => true])->save();
    $admin = User::factory()->admin()->create();

    $uuid = $this->actingAs($admin)->postJson(route('assessments.template.publish', $source))->json('template.uuid');
    $template = Assessment::query()->where('uuid', $uuid)->firstOrFail();

    expect($template->members()->count())->toBe(0)
        ->and($template->is_public)->toBeFalse();
});

test('publishing again makes a second, independent template', function () {
    $source = sourceAssessment();
    $admin = User::factory()->admin()->create();

    $first = $this->actingAs($admin)->postJson(route('assessments.template.publish', $source))->json('template.uuid');
    $second = $this->actingAs($admin)->postJson(route('assessments.template.publish', $source))->json('template.uuid');

    expect($first)->not->toBe($second)
        ->and(Assessment::query()->where('is_template', true)->count())->toBe(2);
});

test('a template cannot be published', function () {
    $template = Assessment::factory()->template()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->postJson(route('assessments.template.publish', $template))->assertNotFound();

    expect(Assessment::query()->where('is_template', true)->count())->toBe(1);
});

test('the editor offers Publish to an admin on an assessment, and to nobody else', function () {
    $source = sourceAssessment();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('assessments.show', $source))
        ->assertInertia(fn ($page) => $page->where('abilities.publishTemplate', true));
    $this->actingAs($source->owner)->get(route('assessments.show', $source))
        ->assertInertia(fn ($page) => $page->where('abilities.publishTemplate', false));
});
