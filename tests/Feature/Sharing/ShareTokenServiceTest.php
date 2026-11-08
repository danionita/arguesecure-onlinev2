<?php

use App\Domain\Sharing\ShareTokenService;
use App\Models\Assessment;
use Illuminate\Support\Facades\DB;

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

/*
 * The lookup column is the SHA-256 of the token and follows it
 * through every write.
 */
test('the stored hash is the SHA-256 of the token and follows generate, regenerate and revoke', function () {
    $assessment = Assessment::factory()->create();
    expect($assessment->fresh()->share_token_hash)->toBeNull();

    $first = ShareTokenService::generate($assessment->fresh());
    expect($assessment->fresh()->share_token_hash)->toBe(hash('sha256', $first));

    $second = ShareTokenService::generate($assessment->fresh());
    expect($assessment->fresh()->share_token_hash)->toBe(hash('sha256', $second))
        ->and(ShareTokenService::resolve($first))->toBeNull();

    ShareTokenService::revoke($assessment->fresh());
    expect($assessment->fresh()->share_token_hash)->toBeNull()
        ->and(ShareTokenService::resolve($second))->toBeNull();
});

test('a token written directly onto the model still resolves', function () {
    $token = str_repeat('k', 43);
    $assessment = Assessment::factory()->create(['share_token' => $token, 'share_token_created_at' => now()]);

    expect($assessment->fresh()->share_token_hash)->toBe(hash('sha256', $token))
        ->and(ShareTokenService::resolve($token)?->id)->toBe($assessment->id);
});

test('a row whose hash matches but whose token does not is not resolved', function () {
    $token = ShareTokenService::generate(Assessment::factory()->create());
    // A stale or tampered hash must not open a link the token does not back.
    DB::table('assessments')->where('share_token', $token)->update(['share_token' => str_repeat('x', 43)]);

    expect(ShareTokenService::resolve($token))->toBeNull();
});

test('the migration gives an existing link its hash', function () {
    $token = str_repeat('m', 43);
    $assessment = Assessment::factory()->create();
    DB::table('assessments')->where('id', $assessment->id)->update(['share_token' => $token, 'share_token_hash' => null]);

    DB::statement('UPDATE assessments SET share_token_hash = SHA2(share_token, 256) WHERE share_token IS NOT NULL');

    expect($assessment->fresh()->share_token_hash)->toBe(hash('sha256', $token));
});

test('the lookup goes through the hash column, not the token column', function () {
    $token = ShareTokenService::generate(Assessment::factory()->create());
    DB::table('assessments')->where('share_token', $token)->update(['share_token_hash' => null]);

    expect(ShareTokenService::resolve($token))->toBeNull();
});

test('a token changed by a raw query keeps the old hash, so the old link still answers 404 and the new one is not found', function () {
    $assessment = Assessment::factory()->create();
    $old = ShareTokenService::generate($assessment->fresh());
    $new = str_repeat('r', 43);

    DB::table('assessments')->where('id', $assessment->id)->update(['share_token' => $new]);

    // The hash still belongs to the old token, but the row no longer holds it.
    expect(ShareTokenService::resolve($old))->toBeNull()
        ->and(ShareTokenService::resolve($new))->toBeNull();
});
