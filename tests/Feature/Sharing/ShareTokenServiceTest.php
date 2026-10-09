<?php

use App\Domain\Sharing\ShareTokenService;
use App\Models\Assessment;

test('generate produces a 43-character base64url token with no padding', function () {
    $assessment = Assessment::factory()->create();

    $token = ShareTokenService::generate($assessment);

    expect($token)->toHaveLength(43)
        ->and($token)->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($assessment->fresh()->share_token)->toBe($token)
        ->and($assessment->fresh()->share_token_created_at)->not->toBeNull();
});

test('regenerating replaces the previous token and its created_at', function () {
    $assessment = Assessment::factory()->create();
    $first = ShareTokenService::generate($assessment->fresh());
    $firstCreatedAt = $assessment->fresh()->share_token_created_at;

    $this->travel(1)->minutes();
    $second = ShareTokenService::generate($assessment->fresh());

    expect($second)->not->toBe($first)
        ->and($assessment->fresh()->share_token)->toBe($second)
        ->and($assessment->fresh()->share_token_created_at->gt($firstCreatedAt))->toBeTrue();
});

test('revoke nulls both the token and its timestamp', function () {
    $assessment = Assessment::factory()->create();
    ShareTokenService::generate($assessment);

    ShareTokenService::revoke($assessment->fresh());

    $fresh = $assessment->fresh();
    expect($fresh->share_token)->toBeNull()
        ->and($fresh->share_token_created_at)->toBeNull();
});

test('resolve finds the assessment by an exact token match and nothing else', function () {
    $assessment = Assessment::factory()->create();
    $token = ShareTokenService::generate($assessment->fresh());

    expect(ShareTokenService::resolve($token)?->id)->toBe($assessment->id)
        ->and(ShareTokenService::resolve(substr($token, 0, 42)))->toBeNull()
        ->and(ShareTokenService::resolve(str_repeat('z', 43)))->toBeNull();
});
