<?php

use App\Domain\Comments\CommentService;
use App\Domain\Dashboard\DashboardData;
use App\Domain\Notifications\NodeAssigned;
use App\Models\Assessment;
use App\Models\Risk;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The feed, the badge, and the second source (W47, item 15).
 *
 * Every query in NotificationController is scoped to
 * `$request->user()->notifications()`. There is no assessment in the route and
 * so nothing for AssessmentPolicy to decide — the only question is whose feed
 * this is, and someone else's id finds no row and 404s. A 403 would confirm
 * the id exists and belongs to somebody, which is the thing not to say.
 */
function assignedNode(): array
{
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->for($assessment)->create(['title' => 'Cardholder data breach']);
    $assignee = User::factory()->create(['name' => 'Dana Okonkwo']);

    $assessment->members()->attach($assignee->id, [
        'role' => 'editor',
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    return [$assessment, $node, $assignee];
}

// --- the second source ---------------------------------------------------

test('assigning a node notifies the assignee', function () {
    [$assessment, $node, $assignee] = assignedNode();

    $this->actingAs($assessment->owner)
        ->postJson(route('assessments.nodes.assignees.store', [$assessment, $node->uuid]), [
            'user_id' => $assignee->id,
        ])
        ->assertCreated();

    $notification = $assignee->fresh()->unreadNotifications()->firstOrFail();

    expect($notification->type)->toBe(NodeAssigned::class)
        ->and($notification->data['kind'])->toBe('assignment')
        ->and($notification->data['node_uuid'])->toBe($node->uuid)
        ->and($notification->data['assigned_by'])->toBe($assessment->owner->name);
});

test('assigning yourself notifies nobody', function () {
    [$assessment, $node] = assignedNode();

    $this->actingAs($assessment->owner)
        ->postJson(route('assessments.nodes.assignees.store', [$assessment, $node->uuid]), [
            'user_id' => $assessment->owner_user_id,
        ])
        ->assertCreated();

    expect($assessment->owner->fresh()->notifications()->count())->toBe(0);
});

test('assigning someone already assigned does not notify again', function () {
    [$assessment, $node, $assignee] = assignedNode();

    foreach ([1, 2] as $ignored) {
        $this->actingAs($assessment->owner)
            ->postJson(route('assessments.nodes.assignees.store', [$assessment, $node->uuid]), [
                'user_id' => $assignee->id,
            ])
            ->assertCreated();
    }

    expect($assignee->fresh()->notifications()->count())->toBe(1);
});

// --- the feed ------------------------------------------------------------

test('the feed lists only my own notifications', function () {
    [$assessment, $node, $assignee] = assignedNode();
    $other = User::factory()->create();

    $this->actingAs($assessment->owner)
        ->postJson(route('assessments.nodes.assignees.store', [$assessment, $node->uuid]), [
            'user_id' => $assignee->id,
        ])->assertCreated();

    $this->actingAs($assignee)
        ->getJson(route('notifications.index'))
        ->assertOk()
        ->assertJsonPath('unread', 1)
        ->assertJsonPath('notifications.0.node_uuid', $node->uuid);

    $this->actingAs($other)
        ->getJson(route('notifications.index'))
        ->assertOk()
        ->assertJsonPath('unread', 0)
        ->assertJsonPath('notifications', []);
});

test('the feed 404s another user’s notification', function () {
    [$assessment, $node, $assignee] = assignedNode();
    $stranger = User::factory()->create();

    $this->actingAs($assessment->owner)
        ->postJson(route('assessments.nodes.assignees.store', [$assessment, $node->uuid]), [
            'user_id' => $assignee->id,
        ])->assertCreated();

    $id = $assignee->fresh()->notifications()->firstOrFail()->id;

    $this->actingAs($stranger)
        ->patchJson(route('notifications.read', $id))
        ->assertNotFound();

    // 404 rather than 403, and unchanged either way.
    expect($assignee->fresh()->unreadNotifications()->count())->toBe(1);
});

test('an anonymous user cannot read the feed', function () {
    $this->getJson(route('notifications.index'))->assertUnauthorized();
});

// --- the badge -----------------------------------------------------------

test('the badge counts unread and clears on open', function () {
    [$assessment, $node, $assignee] = assignedNode();

    $this->actingAs($assessment->owner)
        ->postJson(route('assessments.nodes.assignees.store', [$assessment, $node->uuid]), [
            'user_id' => $assignee->id,
        ])->assertCreated();

    $this->actingAs($assignee)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('nav.notificationCount', 1));

    $this->actingAs($assignee)
        ->patchJson(route('notifications.update'))
        ->assertOk()
        ->assertJsonPath('unread', 0);

    $this->actingAs($assignee)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('nav.notificationCount', 0));
});

test('marking read never touches anyone else’s badge', function () {
    [$assessment, $node, $assignee] = assignedNode();
    $second = User::factory()->create(['name' => 'Kim Novak']);
    $assessment->members()->attach($second->id, [
        'role' => 'editor',
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    foreach ([$assignee, $second] as $person) {
        $this->actingAs($assessment->owner)
            ->postJson(route('assessments.nodes.assignees.store', [$assessment, $node->uuid]), [
                'user_id' => $person->id,
            ])->assertCreated();
    }

    $this->actingAs($assignee)->patchJson(route('notifications.update'))->assertOk();

    expect($assignee->fresh()->unreadNotifications()->count())->toBe(0)
        ->and($second->fresh()->unreadNotifications()->count())->toBe(1);
});

// --- the dashboard's other half --------------------------------------------

/*
 * W42 left `mentions()` as a documented seam with a row shape. It is filled
 * from the notifications already sent, not by re-scanning comment bodies:
 * two implementations of "who was mentioned" would eventually disagree, and
 * the dashboard is the one nobody would check.
 */
test('a mention appears on the mentioned user’s dashboard, unresolved first', function () {
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->for($assessment)->create(['title' => 'Cardholder data breach']);
    $author = User::factory()->create(['name' => 'Sam Author']);
    $dana = User::factory()->create(['name' => 'Dana Okonkwo']);

    foreach ([$author, $dana] as $member) {
        $assessment->members()->attach($member->id, [
            'role' => 'editor',
            'invited_by' => $assessment->owner_user_id,
            'invited_at' => now(),
            'accepted_at' => now(),
        ]);
    }

    $comments = app(CommentService::class);
    $older = $comments->create($assessment, $node, $author, 'Closed one @Dana');
    $comments->create($assessment, $node, $author, 'Open one @Dana Okonkwo');
    $comments->resolve($older, $author);

    $rows = app(DashboardData::class)->for($dana->fresh())['mentions'];

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['resolved'])->toBeFalse()
        ->and($rows[1]['resolved'])->toBeTrue()
        ->and($rows[0]['node_uuid'])->toBe($node->uuid)
        ->and($rows[0]['author'])->toBe('Sam Author')
        ->and($rows[0]['excerpt'])->toContain('Open one');
});

test('another user’s mentions never appear on my dashboard', function () {
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->for($assessment)->create();
    $author = User::factory()->create(['name' => 'Sam Author']);
    $dana = User::factory()->create(['name' => 'Dana Okonkwo']);
    $kim = User::factory()->create(['name' => 'Kim Novak']);

    foreach ([$author, $dana, $kim] as $member) {
        $assessment->members()->attach($member->id, [
            'role' => 'editor',
            'invited_by' => $assessment->owner_user_id,
            'invited_at' => now(),
            'accepted_at' => now(),
        ]);
    }

    app(CommentService::class)
        ->create($assessment, $node, $author, 'Only for @Dana');

    expect(app(DashboardData::class)->for($kim->fresh())['mentions'])->toBe([]);
});
