<?php

use App\Domain\Structure\LinkService;
use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Defence;
use App\Models\Risk;
use App\Models\User;

/*
 * "Add from template" (2.5.1): the editor reads a template's nodes of one
 * type so a person can copy them into the assessment they are working on.
 * Read-only, and gated exactly like using a whole template — AssessmentPolicy's
 * `useAsTemplate` — because copying one node is a smaller version of the same
 * act.
 */

function pickerTemplate(string $title = 'Standard'): Assessment
{
    $template = Assessment::factory()->create(['is_template' => true]);
    // The factory makes the root; the template's name lives on it.
    $template->rootNode()->firstOrFail()->update(['title' => $title]);

    return $template;
}

test('a guest is sent to log in, not shown a template\'s nodes', function () {
    $template = pickerTemplate();

    $this->get(route('templates.options'))->assertRedirect(route('login'));
    $this->get(route('templates.nodes', [$template, 'type' => 'risk']))->assertRedirect(route('login'));
});

test('any signed-in account can list the templates with their node counts', function () {
    $template = pickerTemplate('ISO/IEC 27001');
    $risk = Risk::factory()->for($template)->create();
    $attack = Attack::factory()->for($template)->create();
    Defence::factory()->for($template)->count(2)->create()
        ->each(fn (Defence $d) => app(LinkService::class)->linkAttackToDefence($attack, $d));
    app(LinkService::class)->linkRiskToAttack($risk, $attack);

    // Not a template, and not the caller's: must not be listed.
    $private = Assessment::factory()->create();
    $private->rootNode()->firstOrFail()->update(['title' => 'Somebody else\'s work']);

    $this->actingAs(User::factory()->create())
        ->getJson(route('templates.options'))
        ->assertOk()
        ->assertExactJson(['templates' => [[
            'uuid' => $template->uuid,
            'title' => 'ISO/IEC 27001',
            'counts' => ['risk' => 1, 'attack' => 1, 'defence' => 2],
        ]]]);
});

test('a template\'s nodes of one type come back in natural order, with their parents', function () {
    $template = pickerTemplate();
    $attack = Attack::factory()->for($template)->create(['title' => 'Phishing']);
    $risk = Risk::factory()->for($template)->create(['title' => 'Data breach']);
    app(LinkService::class)->linkRiskToAttack($risk, $attack);

    foreach (['A.5.10 Acceptable use', 'A.5.2 Roles', 'A.5.1 Policies'] as $title) {
        $defence = Defence::factory()->for($template)->create(['title' => $title, 'is_transfer' => false]);
        app(LinkService::class)->linkAttackToDefence($attack, $defence);
    }
    $insurance = Defence::factory()->for($template)->create(['title' => 'Z insurance', 'is_transfer' => true]);
    app(LinkService::class)->linkAttackToDefence($attack, $insurance);

    $response = $this->actingAs(User::factory()->create())
        ->getJson(route('templates.nodes', [$template, 'type' => 'defence']))
        ->assertOk();

    // Natural, not lexical: a lexical sort puts A.5.10 before A.5.2.
    expect(array_column($response->json('nodes'), 'title'))
        ->toBe(['A.5.1 Policies', 'A.5.2 Roles', 'A.5.10 Acceptable use', 'Z insurance']);
    expect($response->json('nodes.0.parents'))->toBe(['Phishing']);
    expect($response->json('nodes.3.is_transfer'))->toBeTrue();

    $attacks = $this->getJson(route('templates.nodes', [$template, 'type' => 'attack']))->json('nodes');
    expect($attacks)->toHaveCount(1)
        ->and($attacks[0]['parents'])->toBe(['Data breach']);
});

test('only a template can be read this way', function () {
    // Otherwise the endpoint reads any assessment's titles by uuid. 404, not
    // 403, so it does not confirm the uuid exists — the same answer
    // templates.use gives.
    $private = Assessment::factory()->create(['is_template' => false]);
    Risk::factory()->for($private)->create(['title' => 'Confidential']);

    $this->actingAs(User::factory()->create())
        ->getJson(route('templates.nodes', [$private, 'type' => 'risk']))
        ->assertNotFound();
});

test('the type must be a node type that can be added', function () {
    $template = pickerTemplate();

    $this->actingAs(User::factory()->create())
        ->getJson(route('templates.nodes', [$template, 'type' => 'root']))
        ->assertUnprocessable();
});
