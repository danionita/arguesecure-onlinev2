<?php

use App\Models\Assessment;
use App\Models\CatalogEntry;
use App\Models\Node;
use App\Models\Risk;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Every route touching an assessment goes through AssessmentPolicy, no
 * exceptions (CLAUDE.md). Per the W20 task brief: browsing the catalogue
 * needs only an authenticated user; inserting an entry into a specific
 * assessment needs `update`. Every case below proves a stranger with no
 * share gets 403 (or a clean 401-via-redirect-to-login for a guest,
 * matching the W12/W14/W19 mutation groups' own convention), the class of
 * defect security.md calls out as legacy defect #4.
 */
function catalogMember(Assessment $assessment, User $user, string $role): void
{
    $assessment->members()->attach($user->id, [
        'role' => $role,
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
}

// --- browsing -------------------------------------------------------------

test('an anonymous user is redirected to login when browsing the catalogue', function () {
    $this->get(route('catalog.index'))->assertRedirect(route('login'));
});

test('any authenticated user can browse the catalogue', function () {
    CatalogEntry::factory()->create(['kind' => 'risk', 'title' => 'Data breach']);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('catalog.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Catalog/Index'));
});

// --- insert -----------------------------------------------------------

test('an anonymous user is rejected from inserting a catalogue entry', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);
    $entry = CatalogEntry::factory()->create(['kind' => 'risk']);

    $this->post(route('assessments.catalog-entries.insert', ['assessment' => $assessment, 'catalogEntry' => $entry]))
        ->assertRedirect(route('login'));

    expect(Node::withoutGlobalScopes()->where('assessment_id', $assessment->id)->where('type', 'risk')->count())->toBe(0);
});

test('a logged-in stranger with no share cannot insert a catalogue entry', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);
    $entry = CatalogEntry::factory()->create(['kind' => 'risk']);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->post(route('assessments.catalog-entries.insert', ['assessment' => $assessment, 'catalogEntry' => $entry]))
        ->assertForbidden();
});

test('a viewer collaborator cannot insert a catalogue entry', function () {
    $assessment = Assessment::factory()->create();
    $entry = CatalogEntry::factory()->create(['kind' => 'risk']);
    $viewer = User::factory()->create();
    catalogMember($assessment, $viewer, 'viewer');

    $this->actingAs($viewer)
        ->post(route('assessments.catalog-entries.insert', ['assessment' => $assessment, 'catalogEntry' => $entry]))
        ->assertForbidden();
});

test('an editor collaborator can insert a catalogue entry', function () {
    $assessment = Assessment::factory()->create();
    $entry = CatalogEntry::factory()->create(['kind' => 'risk', 'title' => 'Ransomware']);
    $editor = User::factory()->create();
    catalogMember($assessment, $editor, 'editor');

    $this->actingAs($editor)
        ->post(route('assessments.catalog-entries.insert', ['assessment' => $assessment, 'catalogEntry' => $entry]))
        ->assertCreated();

    expect(Node::withoutGlobalScopes()->where('assessment_id', $assessment->id)->where('type', 'risk')->count())->toBe(1);
});

test('the owner can insert a catalogue entry', function () {
    $assessment = Assessment::factory()->create();
    $entry = CatalogEntry::factory()->create(['kind' => 'risk']);

    $this->actingAs($assessment->owner)
        ->post(route('assessments.catalog-entries.insert', ['assessment' => $assessment, 'catalogEntry' => $entry]))
        ->assertCreated();
});

test('an admin can insert a catalogue entry into any assessment', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);
    $entry = CatalogEntry::factory()->create(['kind' => 'risk']);
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->post(route('assessments.catalog-entries.insert', ['assessment' => $assessment, 'catalogEntry' => $entry]))
        ->assertCreated();
});

test('inserting an attack entry under a risk from another assessment 404s rather than leaking through the route', function () {
    $assessment = Assessment::factory()->create();
    $otherAssessment = Assessment::factory()->create();
    $riskElsewhere = Risk::factory()->for($otherAssessment)->create();
    $entry = CatalogEntry::factory()->create(['kind' => 'attack']);

    $this->actingAs($assessment->owner)
        ->post(route('assessments.catalog-entries.insert', ['assessment' => $assessment, 'catalogEntry' => $entry]), [
            'parent_uuid' => $riskElsewhere->uuid,
        ])
        ->assertNotFound();
});
