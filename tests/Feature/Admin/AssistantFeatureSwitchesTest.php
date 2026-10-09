<?php

/**
 * One switch per assistant feature (W87, item 20, PLAN-2.5.md D48).
 *
 * D25's three conditions are unchanged — the deployment has a runtime, an
 * admin switched the assistant on, the runtime answers — and this adds a
 * fourth that **can only ever subtract**. That asymmetry is the whole of D48
 * and it is what most of this file asserts.
 *
 * Three things are deliberately not folded together here:
 *
 * - **Each switch is asserted on its own**, both ways round: with it off the
 *   feature is refused, and with it off *the other two still work*. A single
 *   "the switches work" test passes with two of them deleted — the failure
 *   shape W68 wrote up and W85's brief repeats.
 * - **Every switch is asserted against an unconfigured deployment.** No value
 *   an admin can store may make a feature work where `config('assistant.enabled')`
 *   is false, at the endpoint or in the form.
 * - **Both halves of each feature are checked**: the endpoint that runs the
 *   job, and the page prop that decides whether a control is offered at all.
 *   Gating only the endpoint would leave a button that 503s, which is the
 *   `registration_locked` mistake `Availability`'s own docblock cites.
 */

use App\Domain\Accounts\InstanceSettings;
use App\Domain\Assistant\Availability;
use App\Domain\Assistant\Health;
use App\Models\Assessment;
use App\Models\Risk;
use App\Models\User;

/** A deployment with a runtime, an admin who switched it on, and a healthy probe. */
function assistantUp(): void
{
    config()->set('assistant.enabled', true);
    InstanceSettings::update(['assistant_enabled' => true]);

    test()->mock(Health::class, fn ($mock) => $mock->shouldReceive('isUp')->andReturn(true));
}

function featureOff(string $column): void
{
    InstanceSettings::update([$column => false]);
}

// --- the default -----------------------------------------------------------

test('the three features default to on, unlike the assistant itself', function () {
    $settings = InstanceSettings::all();

    // The instance switch defaults off: adding a container does not hand the
    // feature to users, somebody decides. These default on, because they are
    // read only *after* that decision — defaulting them off would make
    // enabling the assistant a two-step act whose second step is invisible.
    expect($settings['assistant_enabled'])->toBeFalse()
        ->and($settings['assistant_help_enabled'])->toBeTrue()
        ->and($settings['assistant_suggestions_enabled'])->toBeTrue()
        ->and($settings['assistant_summary_enabled'])->toBeTrue();
})->group('assistant');

// --- each switch, on its own ----------------------------------------------

test('help is refused when its switch is off, and the other two are not', function () {
    assistantUp();
    featureOff('assistant_help_enabled');

    $availability = app(Availability::class);

    expect($availability->isAvailable(Availability::FEATURE_HELP))->toBeFalse()
        ->and($availability->state(Availability::FEATURE_HELP))->toBe(Availability::FEATURE_DISABLED)
        ->and($availability->isAvailable(Availability::FEATURE_SUGGESTIONS))->toBeTrue()
        ->and($availability->isAvailable(Availability::FEATURE_SUMMARY))->toBeTrue();
})->group('assistant');

test('suggestions are refused when their switch is off, and the other two are not', function () {
    assistantUp();
    featureOff('assistant_suggestions_enabled');

    $availability = app(Availability::class);

    expect($availability->isAvailable(Availability::FEATURE_SUGGESTIONS))->toBeFalse()
        ->and($availability->state(Availability::FEATURE_SUGGESTIONS))->toBe(Availability::FEATURE_DISABLED)
        ->and($availability->isAvailable(Availability::FEATURE_HELP))->toBeTrue()
        ->and($availability->isAvailable(Availability::FEATURE_SUMMARY))->toBeTrue();
})->group('assistant');

test('the summary is refused when its switch is off, and the other two are not', function () {
    assistantUp();
    featureOff('assistant_summary_enabled');

    $availability = app(Availability::class);

    expect($availability->isAvailable(Availability::FEATURE_SUMMARY))->toBeFalse()
        ->and($availability->state(Availability::FEATURE_SUMMARY))->toBe(Availability::FEATURE_DISABLED)
        ->and($availability->isAvailable(Availability::FEATURE_HELP))->toBeTrue()
        ->and($availability->isAvailable(Availability::FEATURE_SUGGESTIONS))->toBeTrue();
})->group('assistant');

test('a feature-disabled reason is not the assistant-disabled one', function () {
    assistantUp();
    featureOff('assistant_summary_enabled');

    $availability = app(Availability::class);

    // "The assistant is switched off" would be a false statement on an
    // instance where the other two features are working, and would send
    // someone looking in the wrong place.
    expect($availability->reason(Availability::FEATURE_SUMMARY))
        ->toBe('This assistant feature is switched off for this instance.')
        ->and($availability->reason())->toBeNull();
})->group('assistant');

// --- the endpoints ---------------------------------------------------------

test('the help endpoint refuses when help is switched off', function () {
    assistantUp();
    featureOff('assistant_help_enabled');

    test()->actingAs(User::factory()->create())
        ->postJson(route('assistant.help'), ['question' => 'What is a transfer?'])
        ->assertStatus(503);
})->group('assistant');

test('the suggestion endpoint refuses when suggestions are switched off', function () {
    assistantUp();
    featureOff('assistant_suggestions_enabled');

    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create();

    test()->actingAs($assessment->owner)
        ->postJson(route('assessments.assistant.suggestions', $assessment), [
            'parent_uuid' => $risk->uuid,
            'type' => 'attack',
        ])
        ->assertStatus(503);
})->group('assistant');

test('the summary endpoint refuses when the summary is switched off', function () {
    assistantUp();
    featureOff('assistant_summary_enabled');

    $assessment = Assessment::factory()->create();

    test()->actingAs($assessment->owner)
        ->postJson(route('assessments.assistant.summary', $assessment))
        ->assertStatus(503);
})->group('assistant');

// --- the props that decide whether a control is offered --------------------

test('the editor offers no suggest control when suggestions are switched off', function () {
    assistantUp();
    $assessment = Assessment::factory()->create();

    $on = test()->actingAs($assessment->owner)
        ->get(route('assessments.show', $assessment->uuid))
        ->viewData('page')['props']['assistantAvailable'];

    featureOff('assistant_suggestions_enabled');

    $off = test()->actingAs($assessment->owner)
        ->get(route('assessments.show', $assessment->uuid))
        ->viewData('page')['props']['assistantAvailable'];

    // Both directions. Asserting only the "off" half would pass on a prop that
    // was hard-coded false.
    expect($on)->toBeTrue()->and($off)->toBeFalse();
})->group('assistant');

test('the report offers no draft control when the summary is switched off', function () {
    assistantUp();
    $assessment = Assessment::factory()->create();

    $on = test()->actingAs($assessment->owner)
        ->get(route('assessments.report.show', $assessment->uuid))
        ->viewData('page')['props']['assessment']['assistantAvailable'];

    featureOff('assistant_summary_enabled');

    $off = test()->actingAs($assessment->owner)
        ->get(route('assessments.report.show', $assessment->uuid))
        ->viewData('page')['props']['assessment']['assistantAvailable'];

    expect($on)->toBeTrue()->and($off)->toBeFalse();
})->group('assistant');

test('the help page offers no ask control when help is switched off', function () {
    assistantUp();

    $user = User::factory()->create();

    $on = test()->actingAs($user)->get(route('help.index'))
        ->viewData('page')['props']['assistant']['available'];

    featureOff('assistant_help_enabled');

    $off = test()->actingAs($user)->get(route('help.index'))
        ->viewData('page')['props']['assistant']['available'];

    expect($on)->toBeTrue()->and($off)->toBeFalse();
})->group('assistant');

// --- subordinate to Availability, always ----------------------------------

test('no feature switch can enable a feature on a deployment with no runtime', function (string $feature, string $column) {
    // The deployment condition, which no admin can change from any screen.
    config()->set('assistant.enabled', false);

    // Everything an admin *could* set, set to its most permissive value.
    InstanceSettings::update([
        'assistant_enabled' => true,
        $column => true,
    ]);

    $availability = app(Availability::class);

    expect($availability->isAvailable($feature))->toBeFalse()
        // NOT_CONFIGURED, not FEATURE_DISABLED: the feature does not exist
        // here, which is a different thing from being switched off, and
        // `reason()` is deliberately silent about it.
        ->and($availability->state($feature))->toBe(Availability::NOT_CONFIGURED)
        ->and($availability->reason($feature))->toBeNull();
})->with([
    'help' => [Availability::FEATURE_HELP, 'assistant_help_enabled'],
    'suggestions' => [Availability::FEATURE_SUGGESTIONS, 'assistant_suggestions_enabled'],
    'summary' => [Availability::FEATURE_SUMMARY, 'assistant_summary_enabled'],
])->group('assistant');

test('a switched-off feature on an unconfigured deployment still says nothing at all', function (string $feature, string $column) {
    /*
     * The *other* ordering, and the one the two tests above cannot see.
     *
     * They set the feature switch to `true`, so moving the
     * `FEATURE_DISABLED` branch above `configured()` would not change their
     * result. This one switches the feature **off** as well, which is the only
     * arrangement where the order of those two branches is observable.
     *
     * It has to stay `NOT_CONFIGURED`. D25: a deployment with no inference
     * runtime shows *nothing at all* — no disabled control, no explanation, no
     * banner on an instance that never had the feature — and `reason()` is
     * deliberately silent for it. `FEATURE_DISABLED` says "switched off for
     * this instance", which invites an admin to go looking for a switch that
     * would not help, on a deployment where the answer is a compose file.
     */
    config()->set('assistant.enabled', false);
    InstanceSettings::update(['assistant_enabled' => true, $column => false]);

    $availability = app(Availability::class);

    expect($availability->state($feature))->toBe(Availability::NOT_CONFIGURED)
        ->and($availability->state($feature))->not->toBe(Availability::FEATURE_DISABLED)
        ->and($availability->reason($feature))->toBeNull();
})->with([
    'help' => [Availability::FEATURE_HELP, 'assistant_help_enabled'],
    'suggestions' => [Availability::FEATURE_SUGGESTIONS, 'assistant_suggestions_enabled'],
    'summary' => [Availability::FEATURE_SUMMARY, 'assistant_summary_enabled'],
])->group('assistant');

test('a switched-off feature while the assistant is off says the assistant is off', function (string $feature, string $column) {
    // The same ordering question one level down: DISABLED, not
    // FEATURE_DISABLED. Both are true statements here, and the assistant being
    // off is the one that explains every feature at once.
    config()->set('assistant.enabled', true);
    InstanceSettings::update(['assistant_enabled' => false, $column => false]);

    expect(app(Availability::class)->state($feature))->toBe(Availability::DISABLED);
})->with([
    'help' => [Availability::FEATURE_HELP, 'assistant_help_enabled'],
    'suggestions' => [Availability::FEATURE_SUGGESTIONS, 'assistant_suggestions_enabled'],
    'summary' => [Availability::FEATURE_SUMMARY, 'assistant_summary_enabled'],
])->group('assistant');

test('no feature switch can enable a feature while the assistant itself is off', function (string $feature, string $column) {
    config()->set('assistant.enabled', true);
    InstanceSettings::update(['assistant_enabled' => false, $column => true]);

    $availability = app(Availability::class);

    expect($availability->isAvailable($feature))->toBeFalse()
        ->and($availability->state($feature))->toBe(Availability::DISABLED);
})->with([
    'help' => [Availability::FEATURE_HELP, 'assistant_help_enabled'],
    'suggestions' => [Availability::FEATURE_SUGGESTIONS, 'assistant_suggestions_enabled'],
    'summary' => [Availability::FEATURE_SUMMARY, 'assistant_summary_enabled'],
])->group('assistant');

test('a switched-on feature is still refused while the runtime is unreachable', function () {
    config()->set('assistant.enabled', true);
    InstanceSettings::update(['assistant_enabled' => true, 'assistant_summary_enabled' => true]);
    test()->mock(Health::class, fn ($mock) => $mock->shouldReceive('isUp')->andReturn(false));

    $availability = app(Availability::class);

    // The fourth condition is checked before the probe — a feature nobody
    // wants should not cost a network call — but a feature somebody *does*
    // want still has to pass it.
    expect($availability->state(Availability::FEATURE_SUMMARY))->toBe(Availability::UNREACHABLE);
})->group('assistant');

// --- the form -------------------------------------------------------------

test('the settings form refuses to switch a feature on where there is no runtime', function (string $column) {
    config()->set('assistant.enabled', false);

    $admin = User::factory()->create(['is_admin' => true]);

    test()->actingAs($admin)
        ->put(route('admin.assistant.update'), [
            ...InstanceSettings::all(),
            $column => true,
        ])
        ->assertSessionHasErrors($column);

    expect(InstanceSettings::all()[$column])->toBeTrue();
})->with([
    'help' => ['assistant_help_enabled'],
    'suggestions' => ['assistant_suggestions_enabled'],
    'summary' => ['assistant_summary_enabled'],
])->group('assistant');

test('the settings form saves the three switches where there is a runtime', function () {
    config()->set('assistant.enabled', true);

    $admin = User::factory()->create(['is_admin' => true]);

    test()->actingAs($admin)
        ->put(route('admin.assistant.update'), [
            ...InstanceSettings::all(),
            'assistant_enabled' => true,
            'assistant_help_enabled' => true,
            'assistant_suggestions_enabled' => false,
            'assistant_summary_enabled' => false,
        ])
        ->assertSessionHasNoErrors();

    $settings = InstanceSettings::all();

    // Each read back on its own: a single "the save worked" assertion would
    // pass if all three were being written from one field.
    expect($settings['assistant_help_enabled'])->toBeTrue()
        ->and($settings['assistant_suggestions_enabled'])->toBeFalse()
        ->and($settings['assistant_summary_enabled'])->toBeFalse();
})->group('assistant');

test('the settings page sends all three switches', function () {
    config()->set('assistant.enabled', true);

    $props = test()->actingAs(User::factory()->create(['is_admin' => true]))
        ->get(route('admin.assistant.edit'))
        ->viewData('page')['props'];

    expect($props['settings'])->toHaveKeys([
        'assistant_enabled',
        'assistant_help_enabled',
        'assistant_suggestions_enabled',
        'assistant_summary_enabled',
    ]);
})->group('assistant');

test('an unknown feature name hides a control rather than granting one', function () {
    assistantUp();

    // A typo in a call site should fail closed. Failing open would offer a
    // control that no switch can turn off.
    expect(InstanceSettings::assistantFeatureEnabled('summaries'))->toBeFalse()
        ->and(app(Availability::class)->isAvailable('summaries'))->toBeFalse();
})->group('assistant');
