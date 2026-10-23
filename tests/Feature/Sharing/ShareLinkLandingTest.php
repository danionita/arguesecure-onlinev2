<?php

use App\Domain\Sharing\ShareTokenService;
use App\Models\Assessment;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * W114: a share link lands on the assessment itself, read-only. Until 2.7
 * it redirected to the dashboard, which requires a login, so a holder
 * without an account met the sign-in page although the grant had worked.
 * Asserted by following the redirect as a guest, because the redirect
 * target alone would not show that the page behind it lets them in.
 */
test('a guest presenting a valid token lands on the assessment and sees it read-only', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);
    $token = ShareTokenService::generate($assessment);

    $this->get('/s/'.$token)
        ->assertRedirect(route('assessments.show', $assessment));

    $this->followingRedirects()
        ->get('/s/'.$token)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Assessment/Show')
            ->where('assessment.uuid', $assessment->uuid)
            ->where('abilities.update', false)
        );

    $this->assertGuest();
});

test('the landing URL carries no token', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);
    $token = ShareTokenService::generate($assessment);

    $location = $this->get('/s/'.$token)->headers->get('Location');

    expect($location)->toBe(route('assessments.show', $assessment))
        ->and($location)->not->toContain($token);
});

test('an invalid token still answers 404 and opens nothing', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);
    ShareTokenService::generate($assessment);

    $this->get('/s/'.str_repeat('b', 43))->assertNotFound();

    $this->get(route('assessments.show', $assessment))->assertForbidden();
});
