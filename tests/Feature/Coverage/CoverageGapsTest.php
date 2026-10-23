<?php

use App\Domain\Calculation\CalculationCache;
use App\Domain\Calculation\RiskCalculator;
use App\Domain\Catalog\CatalogSearch;
use App\Domain\Graph\AssessmentGraphLoader;
use App\Domain\Reporting\Export\ExportFormat;
use App\Events\TotalsRecomputed;
use App\Jobs\RecalculateAssessment;
use App\Models\Assessment;
use App\Models\AttackDefence;
use App\Models\CatalogEntry;
use App\Models\ScaleLevel;
use App\Models\User;
use Illuminate\Support\Facades\Event;

/*
 * W119: paths the first coverage measurement (94.5% of statements) showed no
 * test reaches, chosen for what a regression there would cost.
 */

// --- AttackDefence::strengthFraction (calculation-engine.md §6.1) -----------

test('a quantitative strength is the stored fraction', function () {
    $link = new AttackDefence;
    $link->strength_mode = 'quantitative';
    $link->strength_fraction = 0.8;

    expect($link->strengthFraction())->toBe(0.8);
});

test('a qualitative strength is its level\'s representative value, and unset when the level is missing', function () {
    $level = new ScaleLevel;
    $level->representative = 0.35;

    $link = new AttackDefence;
    $link->strength_mode = 'qualitative';
    $link->setRelation('strengthLevel', $level);
    expect($link->strengthFraction())->toBe(0.35);

    $link->setRelation('strengthLevel', null);
    expect($link->strengthFraction())->toBeNull();
});

test('a link with no strength mode has no strength, never zero', function () {
    $link = new AttackDefence;
    $link->strength_fraction = 0.9;

    expect($link->strengthFraction())->toBeNull();
});

// --- CatalogSearch visibility ------------------------------------------------

test('catalogue search shows system and public entries to everyone, and private entries only to their owner', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $system = CatalogEntry::factory()->create(['title' => 'System entry']);
    $public = CatalogEntry::factory()->create(['title' => 'Public entry', 'is_public' => true]);
    $private = CatalogEntry::factory()->create(['title' => 'Private entry']);
    $private->owner_user_id = $owner->id;
    $private->save();
    $public->owner_user_id = $owner->id;
    $public->save();

    $titles = fn (?User $user) => app(CatalogSearch::class)->search($user)->pluck('title')->all();

    expect($titles($owner))->toBe(['Private entry', 'Public entry', 'System entry']);
    expect($titles($other))->toBe(['Public entry', 'System entry']);
    expect($titles(null))->toBe(['Public entry', 'System entry']);
});

test('catalogue search filters by kind and by title or description text', function () {
    CatalogEntry::factory()->create(['kind' => 'risk', 'title' => 'Phishing', 'description' => 'mail']);
    CatalogEntry::factory()->create(['kind' => 'attack', 'title' => 'Credential stuffing', 'description' => 'phishing kit reuse']);
    CatalogEntry::factory()->create(['kind' => 'attack', 'title' => 'Tailgating', 'description' => null]);

    $search = app(CatalogSearch::class);

    expect($search->search(null, 'attack')->pluck('title')->all())->toBe(['Credential stuffing', 'Tailgating']);
    expect($search->search(null, null, 'phish')->pluck('title')->all())->toBe(['Credential stuffing', 'Phishing']);
    expect($search->search(null, 'attack', 'phish')->pluck('title')->all())->toBe(['Credential stuffing']);
    expect($search->search(null, null, '')->count())->toBe(3);
});

// --- ExportFormat ------------------------------------------------------------

test('every export format has a distinct label and its own extension', function () {
    $labels = array_map(fn (ExportFormat $f) => $f->label(), ExportFormat::cases());

    expect($labels)->toBe([
        'Web page (.html)',
        'Spreadsheet data (.csv)',
        'Data interchange (.json)',
        'Excel workbook (.xlsx)',
        'Word document (.docx)',
    ]);
    expect(array_map(fn (ExportFormat $f) => $f->extension(), ExportFormat::cases()))
        ->toBe(['html', 'csv', 'json', 'xlsx', 'docx']);
});

// --- RecalculateAssessment ---------------------------------------------------

test('a job for an assessment deleted since dispatch broadcasts nothing and does not throw', function () {
    Event::fake([TotalsRecomputed::class]);
    (new RecalculateAssessment(999999))->handle(app(AssessmentGraphLoader::class), app(RiskCalculator::class), app(CalculationCache::class));

    Event::assertNotDispatched(TotalsRecomputed::class);
});

// --- UndoController: redo on an empty stack ----------------------------------

test('redo with nothing undone is refused with Nothing to redo', function () {
    $assessment = Assessment::factory()->create();
    $editor = User::factory()->create();
    $assessment->members()->attach($editor->id, [
        'role' => 'editor',
        'invited_by' => $assessment->owner_user_id,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    $response = $this->actingAs($editor)->postJson(route('assessments.redo.store', $assessment));

    $response->assertStatus(404);
    expect($response->json('message'))->toBe('Nothing to redo.');
});
