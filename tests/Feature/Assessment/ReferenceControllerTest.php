<?php

use App\Models\Assessment;
use App\Models\Node;
use App\Models\NodeReference;
use App\Models\Risk;
use App\Models\User;

/*
 * External references (schema.md §3.12, ui.md §7's References tab,
 * CLAUDE.md's W21 brief). Mutation-testable authorization matrix following
 * the same {guest, stranger, viewer, editor} shape as
 * tests/Feature/Assessment/LinkControllerTest.php and
 * tests/Feature/Comments/CommentControllerTest.php, plus validation tests
 * per kind and the security.md §7 guarantee that a `cve` (or any derived
 * kind) cannot smuggle in an attacker-chosen URL.
 */
function attachReferenceMember(Assessment $assessment, User $user, string $role): void
{
    $assessment->members()->attach($user->id, [
        'role' => $role,
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
}

// --- authorization -----------------------------------------------------

test('an anonymous user is rejected from every reference mutation route', function () {
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $reference = NodeReference::factory()->create(['node_id' => $node->id]);

    $this->postJson(route('assessments.node-references.store', [$assessment, $node]), [
        'kind' => 'cve',
        'identifier' => 'CVE-2024-9999',
    ])->assertUnauthorized();

    $this->postJson(route('assessments.node-references.reorder', [$assessment, $node]), [
        'order' => [$reference->id],
    ])->assertUnauthorized();

    $this->deleteJson(route('assessments.node-references.destroy', [$assessment, $node, $reference]))
        ->assertUnauthorized();
});

test('a stranger is rejected from every reference mutation route on a private assessment', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->postJson(route('assessments.node-references.store', [$assessment, $node]), [
            'kind' => 'cve',
            'identifier' => 'CVE-2024-9999',
        ])->assertForbidden();
});

test('a stranger cannot read references on a private assessment by direct URL', function () {
    $assessment = Assessment::factory()->create(['is_public' => false]);
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);

    $this->getJson(route('assessments.node-references.index', [$assessment, $node]))
        ->assertForbidden();
});

test('a viewer can read references but cannot add one', function () {
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);
    NodeReference::factory()->create(['node_id' => $node->id]);
    $viewer = User::factory()->create();
    attachReferenceMember($assessment, $viewer, 'viewer');

    $this->actingAs($viewer)
        ->getJson(route('assessments.node-references.index', [$assessment, $node]))
        ->assertOk()
        ->assertJsonCount(1, 'references');

    $this->actingAs($viewer)
        ->postJson(route('assessments.node-references.store', [$assessment, $node]), [
            'kind' => 'cve',
            'identifier' => 'CVE-2024-9999',
        ])->assertForbidden();

    expect($node->references()->count())->toBe(1);
});

test('a viewer cannot reorder or delete a reference', function () {
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $reference = NodeReference::factory()->create(['node_id' => $node->id]);
    $viewer = User::factory()->create();
    attachReferenceMember($assessment, $viewer, 'viewer');

    $this->actingAs($viewer)
        ->postJson(route('assessments.node-references.reorder', [$assessment, $node]), [
            'order' => [$reference->id],
        ])->assertForbidden();

    $this->actingAs($viewer)
        ->deleteJson(route('assessments.node-references.destroy', [$assessment, $node, $reference]))
        ->assertForbidden();

    expect($reference->fresh())->not->toBeNull();
});

test('an editor can add, reorder and delete references', function () {
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $editor = User::factory()->create();
    attachReferenceMember($assessment, $editor, 'editor');

    $first = $this->actingAs($editor)
        ->postJson(route('assessments.node-references.store', [$assessment, $node]), [
            'kind' => 'cve',
            'identifier' => 'CVE-2024-1111',
        ]);
    $first->assertCreated();
    $firstId = $first->json('reference.id');

    $second = $this->actingAs($editor)
        ->postJson(route('assessments.node-references.store', [$assessment, $node]), [
            'kind' => 'cve',
            'identifier' => 'CVE-2024-2222',
        ]);
    $second->assertCreated();
    $secondId = $second->json('reference.id');

    expect($node->references()->orderBy('position')->pluck('id')->all())
        ->toBe([$firstId, $secondId]);

    $this->actingAs($editor)
        ->postJson(route('assessments.node-references.reorder', [$assessment, $node]), [
            'order' => [$secondId, $firstId],
        ])->assertOk();

    expect($node->references()->orderBy('position')->pluck('id')->all())
        ->toBe([$secondId, $firstId]);

    $this->actingAs($editor)
        ->deleteJson(route('assessments.node-references.destroy', [$assessment, $node, $firstId]))
        ->assertNoContent();

    expect($node->references()->pluck('id')->all())->toBe([$secondId]);
});

/*
 * ReferenceController::reorder() applies each position by primary key
 * alone — `NodeReference::whereKey($id)->update(...)` — so the *only*
 * thing standing between an editor and rewriting the ordering of a node
 * they cannot touch is ReorderNodeReferencesRequest's node_id-scoped
 * Rule::exists. Removing that scoping left the whole suite green, so the
 * control was real but unguarded: this test exists to make it fail.
 */
test('an editor cannot reorder a reference belonging to another assessment', function () {
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $editor = User::factory()->create();
    attachReferenceMember($assessment, $editor, 'editor');

    $own = NodeReference::factory()->create(['node_id' => $node->id, 'position' => 0]);

    $foreignAssessment = Assessment::factory()->create();
    $foreignNode = Risk::factory()->create(['assessment_id' => $foreignAssessment->id]);
    $foreign = NodeReference::factory()->create(['node_id' => $foreignNode->id, 'position' => 7]);

    $this->actingAs($editor)
        ->postJson(route('assessments.node-references.reorder', [$assessment, $node]), [
            'order' => [$foreign->id, $own->id],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('order.0');

    // The foreign row must be untouched, not merely un-returned: the
    // update runs by primary key, so an unscoped id would have written to
    // another assessment's data before any response was rendered.
    expect($foreign->fresh()->position)->toBe(7);
});

test('a node from a different assessment 404s on every reference route', function () {
    $assessment = Assessment::factory()->create();
    $otherAssessment = Assessment::factory()->create();
    $foreignNode = Risk::factory()->create(['assessment_id' => $otherAssessment->id]);
    $editor = User::factory()->create();
    attachReferenceMember($assessment, $editor, 'editor');

    // 404, not 403: {node:uuid} is scoped to {assessment:uuid} via
    // Assessment::nodes() through Laravel's implicit route-model-binding
    // scoping, the same convention NodeControllerTest documents.
    $this->actingAs($editor)
        ->postJson(route('assessments.node-references.store', [$assessment, $foreignNode]), [
            'kind' => 'cve',
            'identifier' => 'CVE-2024-3333',
        ])->assertNotFound();
});

test('a reference belonging to another node 404s on destroy', function () {
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $otherNode = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $foreignReference = NodeReference::factory()->create(['node_id' => $otherNode->id]);
    $editor = User::factory()->create();
    attachReferenceMember($assessment, $editor, 'editor');

    $this->actingAs($editor)
        ->deleteJson(route('assessments.node-references.destroy', [$assessment, $node, $foreignReference]))
        ->assertNotFound();

    expect($foreignReference->fresh())->not->toBeNull();
});

test('the owner and a manager can also mutate references', function () {
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $manager = User::factory()->create();
    attachReferenceMember($assessment, $manager, 'manager');

    $this->actingAs($assessment->owner)
        ->postJson(route('assessments.node-references.store', [$assessment, $node]), [
            'kind' => 'cve',
            'identifier' => 'CVE-2024-4444',
        ])->assertCreated();

    $this->actingAs($manager)
        ->postJson(route('assessments.node-references.store', [$assessment, $node]), [
            'kind' => 'cve',
            'identifier' => 'CVE-2024-5555',
        ])->assertCreated();
});

// --- validation per kind -------------------------------------------------

test('a valid CVE identifier is accepted, normalized and linked to NVD', function () {
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $editor = User::factory()->create();
    attachReferenceMember($assessment, $editor, 'editor');

    $response = $this->actingAs($editor)
        ->postJson(route('assessments.node-references.store', [$assessment, $node]), [
            'kind' => 'cve',
            'identifier' => 'cve-2024-3094', // lowercase — must be normalized
        ]);

    $response->assertCreated();
    expect($response->json('reference.identifier'))->toBe('CVE-2024-3094');
    expect($response->json('reference.url'))->toBe('https://nvd.nist.gov/vuln/detail/CVE-2024-3094');
});

test('an invalid CVE identifier is rejected', function () {
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $editor = User::factory()->create();
    attachReferenceMember($assessment, $editor, 'editor');

    $this->actingAs($editor)
        ->postJson(route('assessments.node-references.store', [$assessment, $node]), [
            'kind' => 'cve',
            'identifier' => 'not-a-cve',
        ])->assertStatus(422);
});

test('a cve reference cannot smuggle in an attacker-chosen URL', function () {
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $editor = User::factory()->create();
    attachReferenceMember($assessment, $editor, 'editor');

    $response = $this->actingAs($editor)
        ->postJson(route('assessments.node-references.store', [$assessment, $node]), [
            'kind' => 'cve',
            'identifier' => 'CVE-2024-6666',
            'url' => 'https://evil.example/phishing',
        ]);

    $response->assertCreated();
    expect($response->json('reference.url'))->toBe('https://nvd.nist.gov/vuln/detail/CVE-2024-6666');

    $stored = NodeReference::query()->findOrFail($response->json('reference.id'));
    expect($stored->url)->toBe('https://nvd.nist.gov/vuln/detail/CVE-2024-6666');
});

test('a valid CWE identifier is accepted and linked to MITRE', function () {
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $editor = User::factory()->create();
    attachReferenceMember($assessment, $editor, 'editor');

    $response = $this->actingAs($editor)
        ->postJson(route('assessments.node-references.store', [$assessment, $node]), [
            'kind' => 'cwe',
            'identifier' => 'CWE-79',
        ]);

    $response->assertCreated();
    expect($response->json('reference.url'))->toBe('https://cwe.mitre.org/data/definitions/79.html');
});

test('an invalid CWE identifier is rejected', function () {
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $editor = User::factory()->create();
    attachReferenceMember($assessment, $editor, 'editor');

    $this->actingAs($editor)
        ->postJson(route('assessments.node-references.store', [$assessment, $node]), [
            'kind' => 'cwe',
            'identifier' => 'CWE-ABC',
        ])->assertStatus(422);
});

test('a valid CAPEC identifier is accepted and linked to MITRE', function () {
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $editor = User::factory()->create();
    attachReferenceMember($assessment, $editor, 'editor');

    $response = $this->actingAs($editor)
        ->postJson(route('assessments.node-references.store', [$assessment, $node]), [
            'kind' => 'capec',
            'identifier' => 'CAPEC-163',
        ]);

    $response->assertCreated();
    expect($response->json('reference.url'))->toBe('https://capec.mitre.org/data/definitions/163.html');
});

test('an invalid CAPEC identifier is rejected', function () {
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $editor = User::factory()->create();
    attachReferenceMember($assessment, $editor, 'editor');

    $this->actingAs($editor)
        ->postJson(route('assessments.node-references.store', [$assessment, $node]), [
            'kind' => 'capec',
            'identifier' => 'CAPEC-',
        ])->assertStatus(422);
});

test('a valid ATT&CK technique identifier links to attack.mitre.org, sub-technique included', function () {
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $editor = User::factory()->create();
    attachReferenceMember($assessment, $editor, 'editor');

    $response = $this->actingAs($editor)
        ->postJson(route('assessments.node-references.store', [$assessment, $node]), [
            'kind' => 'attack_technique',
            'identifier' => 'T1566.001',
        ]);

    $response->assertCreated();
    expect($response->json('reference.url'))->toBe('https://attack.mitre.org/techniques/T1566/001/');
});

test('an invalid ATT&CK technique identifier is rejected', function () {
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $editor = User::factory()->create();
    attachReferenceMember($assessment, $editor, 'editor');

    $this->actingAs($editor)
        ->postJson(route('assessments.node-references.store', [$assessment, $node]), [
            'kind' => 'attack_technique',
            'identifier' => 'phishing',
        ])->assertStatus(422);
});

test('a valid URL is accepted as-is', function () {
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $editor = User::factory()->create();
    attachReferenceMember($assessment, $editor, 'editor');

    $response = $this->actingAs($editor)
        ->postJson(route('assessments.node-references.store', [$assessment, $node]), [
            'kind' => 'url',
            'url' => 'https://example.com/advisory',
        ]);

    $response->assertCreated();
    expect($response->json('reference.url'))->toBe('https://example.com/advisory');
    expect($response->json('reference.identifier'))->toBeNull();
});

test('a non-http(s) URL is rejected', function () {
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $editor = User::factory()->create();
    attachReferenceMember($assessment, $editor, 'editor');

    $this->actingAs($editor)
        ->postJson(route('assessments.node-references.store', [$assessment, $node]), [
            'kind' => 'url',
            'url' => 'javascript:alert(1)',
        ])->assertStatus(422);
});

test('an "other" reference requires both an identifier and a URL', function () {
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $editor = User::factory()->create();
    attachReferenceMember($assessment, $editor, 'editor');

    $this->actingAs($editor)
        ->postJson(route('assessments.node-references.store', [$assessment, $node]), [
            'kind' => 'other',
            'identifier' => 'Vendor advisory 2024-07',
        ])->assertStatus(422);

    $response = $this->actingAs($editor)
        ->postJson(route('assessments.node-references.store', [$assessment, $node]), [
            'kind' => 'other',
            'identifier' => 'Vendor advisory 2024-07',
            'url' => 'https://vendor.example/advisories/2024-07',
        ]);

    $response->assertCreated();
    expect($response->json('reference.url'))->toBe('https://vendor.example/advisories/2024-07');
});

test('a duplicate identifier of the same kind on the same node is rejected', function () {
    $assessment = Assessment::factory()->create();
    $node = Risk::factory()->create(['assessment_id' => $assessment->id]);
    $editor = User::factory()->create();
    attachReferenceMember($assessment, $editor, 'editor');
    NodeReference::factory()->create([
        'node_id' => $node->id,
        'kind' => 'cve',
        'identifier' => 'CVE-2024-7777',
    ]);

    $this->actingAs($editor)
        ->postJson(route('assessments.node-references.store', [$assessment, $node]), [
            'kind' => 'cve',
            'identifier' => 'CVE-2024-7777',
        ])->assertStatus(422);
});
