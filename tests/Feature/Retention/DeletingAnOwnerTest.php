<?php

use App\Models\Assessment;
use App\Models\Scale;
use App\Models\ScaleLevel;
use App\Models\User;
use Database\Seeders\SystemScaleSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    (new SystemScaleSeeder)->run();
});

/**
 * FIXED: deleting a user who owns a deleted, calibrated assessment answered
 * 500 (W113 pinned it; fixed by W121, D81). A calibrated assessment owns its
 * scales and references them with RESTRICT, so the cascade from
 * `assessments.owner_user_id` failed with 1451 once the user row went, and
 * `DestroyUserRequest` and the transfer both skipped deleted assessments.
 * `destroy()` now transfers them with the live ones, or erases them when no
 * target was named.
 */
function deletedCalibratedAssessmentOf(User $owner): Assessment
{
    $assessment = Assessment::factory()->create(['owner_user_id' => $owner->id]);
    $scale = Scale::factory()->forAssessment($assessment->id)->create();
    ScaleLevel::factory()->create(['scale_id' => $scale->id]);
    DB::table('assessments')->where('id', $assessment->id)->update(['impact_scale_id' => $scale->id]);
    $assessment->delete();

    return $assessment;
}

test('FIXED: deleting an owner with a transfer target hands the deleted assessment over, still restorable', function () {
    $admin = User::factory()->admin()->create();
    $owner = User::factory()->create();
    $target = User::factory()->create();
    $assessment = deletedCalibratedAssessmentOf($owner);

    $this->actingAs($admin)
        ->delete(route('admin.users.destroy', $owner), ['transfer_to_user_id' => $target->id])
        ->assertRedirect(route('admin.users.index'));

    expect(User::query()->whereKey($owner->id)->exists())->toBeFalse();

    $kept = Assessment::withTrashed()->whereKey($assessment->id)->first();
    expect($kept)->not->toBeNull()
        ->and($kept->owner_user_id)->toBe($target->id)
        ->and($kept->trashed())->toBeTrue();
});

test('FIXED: deleting an owner with no live assessments and no target erases the deleted ones', function () {
    $admin = User::factory()->admin()->create();
    $owner = User::factory()->create();
    $assessment = deletedCalibratedAssessmentOf($owner);

    $this->actingAs($admin)
        ->delete(route('admin.users.destroy', $owner))
        ->assertRedirect(route('admin.users.index'));

    expect(User::query()->whereKey($owner->id)->exists())->toBeFalse()
        ->and(Assessment::withTrashed()->whereKey($assessment->id)->exists())->toBeFalse()
        ->and(Scale::query()->where('assessment_id', $assessment->id)->exists())->toBeFalse();
});

test('a live assessment still requires a transfer target', function () {
    $admin = User::factory()->admin()->create();
    $owner = User::factory()->create();
    Assessment::factory()->create(['owner_user_id' => $owner->id]);
    deletedCalibratedAssessmentOf($owner);

    $this->actingAs($admin)
        ->delete(route('admin.users.destroy', $owner))
        ->assertSessionHasErrors('transfer_to_user_id');

    expect(User::query()->whereKey($owner->id)->exists())->toBeTrue();
});
