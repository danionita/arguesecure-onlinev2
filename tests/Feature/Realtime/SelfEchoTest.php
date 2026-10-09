<?php

use App\Events\NodeCreated;
use App\Events\NodeUpdated;
use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Support\Facades\Event;

/*
 * FIXED (2.5.1) — RELEASE-2.5.0.md item 22b, the server half.
 *
 * NodeCreated and NodeUpdated used to go to every connection on the
 * assessment's channel, the author's included, and the author's client
 * applied its own echo a second later over whatever had been typed since.
 * Both now call dontBroadcastToCurrentUser(), which records the request's
 * X-Socket-ID (sent by useRealtime.ts) so Reverb leaves exactly that
 * connection out. The author's other tabs have other socket ids and still
 * receive the event; a request with no socket id — a queued job, a console
 * command — still reaches everyone.
 *
 * The client half is tests/js/stores/selfEcho.spec.ts.
 */
function selfEchoEditor(Assessment $assessment): User
{
    $editor = User::factory()->create();
    $assessment->members()->attach($editor->id, [
        'role' => 'editor',
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    return $editor;
}

test('FIXED: NodeCreated leaves out the connection that created the node', function () {
    Event::fake([NodeCreated::class]);
    $assessment = Assessment::factory()->create();
    $editor = selfEchoEditor($assessment);

    $this->actingAs($editor)
        ->withHeader('X-Socket-ID', '1234.5678')
        ->postJson(route('assessments.nodes.store', $assessment), ['type' => 'risk', 'title' => 'Phishing'])
        ->assertCreated();

    Event::assertDispatched(NodeCreated::class, fn (NodeCreated $event) => $event->socket === '1234.5678');
});

test('FIXED: NodeUpdated leaves out the connection that made the edit', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id, 'title' => 'Phis']);
    $editor = selfEchoEditor($assessment);

    Event::fake([NodeUpdated::class]);

    $this->actingAs($editor)
        ->withHeader('X-Socket-ID', '1234.5678')
        ->patchJson(route('assessments.nodes.update', [$assessment, $risk]), [
            'title' => 'Phishing',
            'updated_at' => $risk->updated_at->toJSON(),
        ])
        ->assertOk();

    Event::assertDispatched(NodeUpdated::class, fn (NodeUpdated $event) => $event->socket === '1234.5678');
});

test('without a socket id nobody is left out — nobody has applied it yet', function () {
    Event::fake([NodeCreated::class]);
    $assessment = Assessment::factory()->create();
    $editor = selfEchoEditor($assessment);

    $this->actingAs($editor)
        ->postJson(route('assessments.nodes.store', $assessment), ['type' => 'risk', 'title' => 'Phishing'])
        ->assertCreated();

    Event::assertDispatched(NodeCreated::class, fn (NodeCreated $event) => $event->socket === null);
});

test('the excluded socket travels in the broadcast payload the broadcaster reads', function () {
    $assessment = Assessment::factory()->create();
    $risk = Risk::factory()->create(['assessment_id' => $assessment->id]);

    request()->headers->set('X-Socket-ID', '42.1');
    $event = new NodeUpdated($risk, null, null);

    // BroadcastEvent merges `socket` into broadcastWith(); the Pusher/Reverb
    // broadcaster pulls it out as the connection to skip.
    expect($event->socket)->toBe('42.1');
});

test("a defence's draft carries its status and transfer flag into the create", function () {
    $assessment = Assessment::factory()->create();
    $editor = selfEchoEditor($assessment);
    $attack = Attack::factory()->create(['assessment_id' => $assessment->id]);

    $response = $this->actingAs($editor)
        ->postJson(route('assessments.nodes.store', $assessment), [
            'type' => 'defence',
            'title' => 'Cyber insurance',
            'status' => 'implemented',
            'is_transfer' => true,
            'attack_uuid' => $attack->uuid,
        ])
        ->assertCreated();

    expect($response->json('node.status'))->toBe('implemented')
        ->and($response->json('node.is_transfer'))->toBeTrue();
});
