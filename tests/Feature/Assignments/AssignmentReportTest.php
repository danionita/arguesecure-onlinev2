<?php

use App\Models\Assessment;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Assignees in the report, and the option that gates them (W46, item 16).
 *
 * A report is a document that leaves the room. Who was responsible for which
 * line is internal information, so it is off by default and — unlike every
 * other expansion — the one-click Download does not turn it on either. Both
 * halves are asserted, because "default off" alone would pass while Download
 * quietly published the names.
 */
function assessmentWithAssignee(string $name = 'Dana Okonkwo'): array
{
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->for($assessment)->create(['title' => 'Cardholder data breach']);
    $person = User::factory()->create(['name' => $name]);

    $assessment->members()->attach($person->id, [
        'role' => 'editor',
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    $risk->assignees()->attach($person->id);

    return [$assessment, $risk, $person];
}

test('the report names nobody by default', function () {
    [$assessment] = assessmentWithAssignee();

    $this->actingAs($assessment->owner)
        ->get(route('assessments.report.show', $assessment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.options.showAssignees', false)
            ->where('report.tree.risks.0.assignees', []));
});

test('the Show assignees option puts the names on the tree rows', function () {
    [$assessment] = assessmentWithAssignee();

    $this->actingAs($assessment->owner)
        ->get(route('assessments.report.show', [$assessment, 'show_assignees' => '1']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.options.showAssignees', true)
            ->where('report.tree.risks.0.assignees', ['Dana Okonkwo']));
});

/*
 * The one option `download=1` does not force on. Everything else it expands is
 * a matter of showing more of the assessment; this one adds people's names to
 * a document about to be attached to an email.
 */
test('the one-click download does not publish the names', function () {
    [$assessment] = assessmentWithAssignee();

    $this->actingAs($assessment->owner)
        ->get(route('assessments.report.show', [$assessment, 'download' => '1', 'show_assignees' => '1']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.options.download', true)
            // Everything else expanded, as W33 requires…
            ->where('report.options.includeComments', true)
            ->where('report.options.includeShelved', true)
            // …and this one still off.
            ->where('report.options.showAssignees', false)
            ->where('report.tree.risks.0.assignees', []));
});

test('the report never carries an assignee’s email address', function () {
    [$assessment, , $person] = assessmentWithAssignee();

    $response = $this->actingAs($assessment->owner)
        ->get(route('assessments.report.show', [$assessment, 'show_assignees' => '1']))
        ->assertOk();

    expect($response->getContent())->not->toContain($person->email);
});

/*
 * security.md §9: "Node and link mutations: 120/min per user". Assigning is a
 * node mutation. Asserted by *value*, not by presence — CLAUDE.md: a group
 * whose limiter merely starts with `throttle` is not a guard, and the
 * enclosing 300/min group would satisfy a presence check on its own.
 */
test('the assignment routes carry the node-mutation rate limit', function () {
    $names = [
        'assessments.nodes.assignees.index',
        'assessments.nodes.assignees.store',
        'assessments.nodes.assignees.destroy',
        'assessments.assignee-candidates.index',
    ];

    foreach ($names as $name) {
        $route = Route::getRoutes()->getByName($name);

        expect($route)->not->toBeNull("route {$name} no longer exists — update this list deliberately");

        $middleware = $route?->gatherMiddleware() ?? [];

        expect(in_array('throttle:mutations', $middleware, true))
            ->toBeTrue("{$name} must carry throttle:mutations (security.md §9), got: ".implode(', ', $middleware));
    }
});
