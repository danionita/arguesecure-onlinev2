<?php

use App\Domain\Sharing\ShareTokenService;
use App\Models\Assessment;
use App\Models\AssessmentUser;
use App\Models\User;

require_once __DIR__.'/Capture.php';

function auditMemberOf(Assessment $assessment, string $role): User
{
    $user = User::factory()->create();
    $assessment->members()->attach($user->id, [
        'role' => $role, 'invited_by' => $assessment->owner_user_id, 'invited_at' => now(), 'accepted_at' => now(),
    ]);

    return $user;
}

test('creating and revoking a share link are logged against the assessment, without the token', function () {
    $assessment = Assessment::factory()->create();
    $owner = $assessment->owner;
    $log = captureSecurityLog();

    $this->actingAs($owner)->postJson(route('share.token.store', $assessment))->assertOk();
    $token = $assessment->fresh()->share_token;
    $this->actingAs($owner)->deleteJson(route('share.token.destroy', $assessment))->assertOk();

    foreach (['share.link_created', 'share.link_revoked'] as $event) {
        $line = securityLine($log, $event);
        expect($line['actor']['id'])->toBe($owner->id)
            ->and($line['target'])->toBe(['type' => 'assessment', 'id' => $assessment->uuid])
            ->and($line['ip'])->toBe('127.0.0.1');
    }
    expect(json_encode(securityLines($log)))->not->toContain($token);
});

test('redeeming a share link is logged with no actor and without the token', function () {
    $assessment = Assessment::factory()->create();
    $token = ShareTokenService::generate($assessment);
    $log = captureSecurityLog();

    $this->get("/s/{$token}")->assertRedirect();

    $line = securityLine($log, 'share.link_redeemed');
    expect($line['actor'])->toBeNull()
        ->and($line['target'])->toBe(['type' => 'assessment', 'id' => $assessment->uuid])
        ->and($line['ip'])->toBe('127.0.0.1')
        ->and(json_encode(securityLines($log)))->not->toContain($token);
});

test('a share link that matches nothing is logged as a rejection, without the guess', function () {
    $log = captureSecurityLog();
    $guess = str_repeat('a', 43);

    $this->get("/s/{$guess}")->assertNotFound();

    $line = securityLine($log, 'share.link_rejected');
    expect($line['target'])->toBeNull()
        ->and($line['level'])->toBe('WARNING')
        ->and($line['ip'])->toBe('127.0.0.1')
        ->and(json_encode(securityLines($log)))->not->toContain($guess);
});

test('making an assessment public or private is logged', function () {
    $assessment = Assessment::factory()->create();
    $log = captureSecurityLog();

    $this->actingAs($assessment->owner)->patchJson(route('share.public.update', $assessment), ['is_public' => true])->assertOk();

    $line = securityLine($log, 'share.public_changed');
    expect($line['actor']['id'])->toBe($assessment->owner_user_id)
        ->and($line['target'])->toBe(['type' => 'assessment', 'id' => $assessment->uuid])
        ->and($line['details'])->toBe(['is_public' => true]);
});

test('adding, re-roling and removing a collaborator are logged with the role', function () {
    $assessment = Assessment::factory()->create();
    $owner = $assessment->owner;
    $addable = User::factory()->create();
    $log = captureSecurityLog();

    $this->actingAs($owner)->postJson(route('share.collaborators.store', $assessment), ['user_id' => $addable->id, 'role' => 'viewer'])->assertCreated();
    $row = AssessmentUser::query()->where('assessment_id', $assessment->id)->where('user_id', $addable->id)->firstOrFail();
    $this->actingAs($owner)->patchJson(route('share.collaborators.update', [$assessment, $row]), ['role' => 'editor'])->assertOk();
    $this->actingAs($owner)->deleteJson(route('share.collaborators.destroy', [$assessment, $row]))->assertOk();

    $target = ['type' => 'assessment', 'id' => $assessment->uuid];
    $added = securityLine($log, 'share.collaborator_added');
    expect($added['actor']['id'])->toBe($owner->id)
        ->and($added['target'])->toBe($target)
        ->and($added['details'])->toBe(['user' => ['type' => 'user', 'id' => $addable->id, 'email' => $addable->email], 'role' => 'viewer']);
    $changed = securityLine($log, 'share.collaborator_role_changed');
    expect($changed['actor']['id'])->toBe($owner->id)
        ->and($changed['target'])->toBe($target)
        ->and($changed['details'])->toBe(['user_id' => $addable->id, 'from' => 'viewer', 'to' => 'editor']);
    $removed = securityLine($log, 'share.collaborator_removed');
    expect($removed['actor']['id'])->toBe($owner->id)
        ->and($removed['details'])->toBe(['user_id' => $addable->id, 'role' => 'editor']);
});

test('transferring ownership is logged with both owners', function () {
    $assessment = Assessment::factory()->create();
    $owner = $assessment->owner;
    $manager = auditMemberOf($assessment, 'manager');
    $log = captureSecurityLog();

    $this->actingAs($owner)->postJson(route('share.transfer', $assessment), ['user_id' => $manager->id])->assertOk();

    $line = securityLine($log, 'share.ownership_transferred');
    expect($line['actor']['id'])->toBe($owner->id)
        ->and($line['target'])->toBe(['type' => 'assessment', 'id' => $assessment->uuid])
        ->and($line['details'])->toBe(['from_user_id' => $owner->id, 'to_user_id' => $manager->id]);
});

test('an export is logged with who took it, in what format and by what access', function () {
    $assessment = Assessment::factory()->create();
    $reader = auditMemberOf($assessment, 'viewer');
    $log = captureSecurityLog();

    $this->actingAs($reader)->get(route('assessments.report.export-json', $assessment))->assertOk();

    $line = securityLine($log, 'assessment.exported');
    expect($line['actor']['id'])->toBe($reader->id)
        ->and($line['target'])->toBe(['type' => 'assessment', 'id' => $assessment->uuid])
        ->and($line['details'])->toBe(['format' => 'json', 'access' => 'account'])
        ->and($line['ip'])->toBe('127.0.0.1');
});

test('an export through a share link is logged as such, with no actor', function () {
    $assessment = Assessment::factory()->create();
    $token = ShareTokenService::generate($assessment);
    $this->get("/s/{$token}");
    $log = captureSecurityLog();

    $this->get(route('assessments.report.export-json', $assessment))->assertOk();

    $line = securityLine($log, 'assessment.exported');
    expect($line['actor'])->toBeNull()
        ->and($line['details'])->toBe(['format' => 'json', 'access' => 'share_link']);
});

test('an export that is refused is logged as a denial, not an export', function () {
    $assessment = Assessment::factory()->create();
    $stranger = User::factory()->create();
    $log = captureSecurityLog();

    $this->actingAs($stranger)->get(route('assessments.report.export-json', $assessment))->assertForbidden();

    $events = array_column(securityLines($log), 'event');
    expect($events)->not->toContain('assessment.exported');
    $line = securityLine($log, 'authz.denied');
    expect($line['actor']['id'])->toBe($stranger->id)
        ->and($line['target'])->toBe(['type' => 'assessment', 'id' => $assessment->uuid])
        ->and($line['details']['route'])->toBe('assessments.report.export-json');
});
