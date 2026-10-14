<?php

use App\Domain\References\ControlId;
use App\Domain\References\ReferenceValidator;
use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Defence;
use App\Models\NodeReference;
use App\Models\Risk;
use App\Models\User;

/*
 * PLAN-2.7 D64: a defence cites a framework control as a `control`
 * reference, `FRAMEWORK:CONTROL`, from a closed list of frameworks, each
 * with its own pattern, linked to the framework and never to input.
 */
function controlEditor(Assessment $assessment): User
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

// --- ControlId -------------------------------------------------------------

test('each configured framework accepts its own numbering and rejects another framework\'s', function (string $identifier, bool $valid) {
    expect(ControlId::parse($identifier) !== null)->toBe($valid, $identifier);
})->with([
    ['ISO27001-2022:A.5.23', true],
    ['ISO27001-2022:6.1.3', true],
    ['ISO27001-2022:A.9.1', false],   // Annex A of 2022 stops at theme 8
    ['ISO27001-2022:CC6.1', false],
    ['ISO42001-2023:A.6.2.2', true],
    ['ISO42001-2023:8.4', true],
    ['EUAIACT:ART.9', true],
    ['EUAIACT:ANNEX.III', true],
    ['EUAIACT:ANNEX.3', false],
    ['GDPR:ART.32', true],
    ['GDPR:ART.32(1)(B)', true],
    ['GDPR:A.5.1', false],
    ['NIS2:ART.21(2)(D)', true],
    ['NIS2:ART.21(2)(d)', false],     // stored uppercase; the request normalizes first
    ['SOC2:CC6.1', true],
    ['SOC2:PI1.3', true],
    ['SOC2:CC10.1', false],
    ['CIS8:4.1', true],
    ['CIS8:16.14', true],
    ['CIS8:A.1', false],
    ['CSF2:PR.AA-01', true],
    ['CSF2:ID.RA', true],             // a Category
    ['CSF2:PR.AA-1', false],          // Subcategories are two digits
    ['CSF2:XX.AA-01', false],         // not one of the six Functions
    ['CRA:ART.13(2)', true],
    ['CRA:ANNEX.I.I(2)(A)', true],
    ['CRA:ANNEX.I.II(5)', true],
    ['CRA:ANNEX.I.III(1)', false],    // Annex I has two Parts
    ['CRA:ANNEX.I.I(2)(a)', false],
    ['ISO27001:A.5.23', false],        // the edition is part of the code
    ['A.5.23', false],
    ['ISO27001-2022:', false],
    [':A.5.23', false],
]);

test('the framework list is exactly the nine the standards templates cover', function () {
    expect(ControlId::frameworks())
        ->toBe(['ISO27001-2022', 'ISO42001-2023', 'EUAIACT', 'GDPR', 'NIS2', 'SOC2', 'CIS8', 'CSF2', 'CRA']);
});

test('a regulation\'s article reads the way the regulation writes it', function () {
    expect(ControlId::parse('NIS2:ART.21(2)(D)')?->label())->toBe('Art. 21(2)(d)')
        ->and(ControlId::parse('EUAIACT:ANNEX.III')?->label())->toBe('Annex III')
        ->and(ControlId::parse('CRA:ANNEX.I.I(2)(A)')?->label())->toBe('Annex I Part I(2)(a)')
        ->and(ControlId::parse('CRA:ANNEX.I.II(5)')?->label())->toBe('Annex I Part II(5)')
        ->and(ControlId::parse('CSF2:PR.AA-01')?->label())->toBe('PR.AA-01')
        ->and(ControlId::parse('ISO27001-2022:A.5.23')?->label())->toBe('A.5.23')
        ->and(ControlId::parse('SOC2:CC6.1')?->label())->toBe('CC6.1');
});

test('a control links to its framework, the same link for every control in it', function () {
    expect(ReferenceValidator::deriveUrl('control', 'ISO27001-2022:A.5.23'))
        ->toBe('https://www.iso.org/standard/82875.html')
        ->and(ReferenceValidator::deriveUrl('control', 'ISO27001-2022:4.1'))
        ->toBe('https://www.iso.org/standard/82875.html')
        ->and(ReferenceValidator::deriveUrl('control', 'NIS2:ART.21(2)(D)'))
        ->toBe('https://eur-lex.europa.eu/eli/dir/2022/2555/oj');
});

test('deriving a link for an invalid citation throws rather than linking nowhere', function () {
    ReferenceValidator::deriveUrl('control', 'NOPE:1');
})->throws(InvalidArgumentException::class);

// --- the store route ---------------------------------------------------------

test('an editor cites a control on a defence, normalized, with the framework link', function () {
    $assessment = Assessment::factory()->create();
    $defence = Defence::factory()->create(['assessment_id' => $assessment->id]);

    $response = $this->actingAs(controlEditor($assessment))
        ->postJson(route('assessments.node-references.store', [$assessment, $defence]), [
            'kind' => 'control',
            'identifier' => 'nis2:Art.21(2)(d)',
        ]);

    $response->assertCreated();
    expect($response->json('reference.identifier'))->toBe('NIS2:ART.21(2)(D)')
        ->and($response->json('reference.url'))->toBe('https://eur-lex.europa.eu/eli/dir/2022/2555/oj');
});

test('a URL sent with a control citation is ignored', function () {
    $assessment = Assessment::factory()->create();
    $defence = Defence::factory()->create(['assessment_id' => $assessment->id]);

    $this->actingAs(controlEditor($assessment))
        ->postJson(route('assessments.node-references.store', [$assessment, $defence]), [
            'kind' => 'control',
            'identifier' => 'GDPR:ART.32',
            'url' => 'https://attacker.example/',
        ])->assertCreated();

    expect(NodeReference::query()->sole()->url)->toBe('https://eur-lex.europa.eu/eli/reg/2016/679/oj');
});

test('a control citation on a risk or an attack is refused', function (string $type) {
    $assessment = Assessment::factory()->create();
    $node = ($type === 'risk' ? Risk::factory() : Attack::factory())->create(['assessment_id' => $assessment->id]);

    $this->actingAs(controlEditor($assessment))
        ->postJson(route('assessments.node-references.store', [$assessment, $node]), [
            'kind' => 'control',
            'identifier' => 'ISO27001-2022:A.5.23',
        ])->assertStatus(422)->assertJsonValidationErrors(['kind' => 'Only a defence']);

    expect(NodeReference::query()->count())->toBe(0);
})->with(['risk', 'attack']);

test('an unknown framework or a control its framework does not have is refused, naming the frameworks', function (string $identifier) {
    $assessment = Assessment::factory()->create();
    $defence = Defence::factory()->create(['assessment_id' => $assessment->id]);

    $this->actingAs(controlEditor($assessment))
        ->postJson(route('assessments.node-references.store', [$assessment, $defence]), [
            'kind' => 'control',
            'identifier' => $identifier,
        ])->assertStatus(422)->assertJsonValidationErrors(['identifier' => 'ISO27001-2022, ISO42001-2023, EUAIACT, GDPR, NIS2, SOC2, CIS8, CSF2, CRA']);

    expect(NodeReference::query()->count())->toBe(0);
})->with(['PCI:1.1', 'ISO27001-2022:A.9.1', 'A.5.23']);

test('the same control cited twice on one defence is refused', function () {
    $assessment = Assessment::factory()->create();
    $defence = Defence::factory()->create(['assessment_id' => $assessment->id]);
    $editor = controlEditor($assessment);

    $cite = fn (string $identifier) => $this->actingAs($editor)
        ->postJson(route('assessments.node-references.store', [$assessment, $defence]), [
            'kind' => 'control',
            'identifier' => $identifier,
        ]);

    $cite('SOC2:CC6.1')->assertCreated();
    $cite('soc2:cc6.1')->assertStatus(422);
});

test('a viewer cannot cite a control', function () {
    $assessment = Assessment::factory()->create();
    $defence = Defence::factory()->create(['assessment_id' => $assessment->id]);
    $viewer = User::factory()->create();
    $assessment->members()->attach($viewer->id, [
        'role' => 'viewer',
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    $this->actingAs($viewer)
        ->postJson(route('assessments.node-references.store', [$assessment, $defence]), [
            'kind' => 'control',
            'identifier' => 'CIS8:4.1',
        ])->assertForbidden();
});
