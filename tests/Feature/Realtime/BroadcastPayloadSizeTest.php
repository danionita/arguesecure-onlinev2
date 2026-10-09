<?php

use App\Domain\Calculation\RiskCalculator;
use App\Domain\Graph\AssessmentGraphLoader;
use App\Models\Assessment;
use App\Models\Attack;
use App\Models\Defence;
use App\Models\Risk;

/*
 * TotalsRecomputed carries a whole CalculationResults, and Reverb enforces
 * two size limits on it: `reverb.servers.reverb.max_request_size` on the
 * publish it accepts over HTTP, and `max_message_size` per app. Exceed
 * either and the broadcast is dropped — the queue logs "Pusher error:
 * Payload too large", the event never arrives, and live totals silently
 * stop updating until the next page load. Nothing surfaces to the user.
 *
 * Reverb's own default for both is 10_000, Pusher's chat-message budget,
 * and the demo content exceeded it: 16.8 KB for a seventeen-node
 * assessment, roughly 1 KB per node. So this was not an edge case for
 * unusually large assessments — it was every assessment, in every
 * deployment, from the moment there was anything to calculate.
 *
 * It hid because the e2e suite's assertions could still pass: the client
 * recomputes optimistically in TypeScript (W11), so the numbers on screen
 * were right even when the authoritative broadcast never arrived. Only the
 * queue worker's log said otherwise.
 */

test('a computed results payload fits inside both of Reverb’s size limits', function () {
    $assessment = Assessment::factory()->create();

    // Deliberately larger than the seventeen-node assessment that first
    // exceeded the old limit, so this asserts headroom rather than the exact
    // case already known to fail.
    $risks = Risk::factory()->count(8)->create([
        'assessment_id' => $assessment->id,
        'impact_mode' => 'quantitative',
        'impact_amount' => 125000,
    ]);

    foreach ($risks as $risk) {
        $attacks = Attack::factory()->count(4)->create([
            'assessment_id' => $assessment->id,
            'likelihood_mode' => 'quantitative',
            'likelihood_aro' => 0.4,
        ]);

        foreach ($attacks as $attack) {
            $risk->attacks()->attach($attack->id, ['position' => 0]);

            $defences = Defence::factory()->count(2)->create([
                'assessment_id' => $assessment->id,
                'status' => 'implemented',
            ]);

            foreach ($defences as $defence) {
                $attack->defences()->attach($defence->id, [
                    'position' => 0,
                    'strength_mode' => 'quantitative',
                    'strength_fraction' => 0.5,
                ]);
            }
        }
    }

    $graph = app(AssessmentGraphLoader::class)->load($assessment->refresh());
    $results = app(RiskCalculator::class)->compute($graph);

    // json_encode is what the broadcaster ultimately puts on the wire, so
    // measuring anything else would be measuring the wrong thing.
    $bytes = strlen((string) json_encode($results));

    $maxRequest = (int) config('reverb.servers.reverb.max_request_size');
    $maxMessage = (int) config('reverb.apps.apps.0.max_message_size');

    // Both, not either: raising only one still drops the message, which is
    // the kind of half-fix that looks like the bug was never fixed.
    expect($bytes)->toBeLessThan($maxRequest)
        ->and($bytes)->toBeLessThan($maxMessage);

    // The limits must also stay meaningfully above Reverb's 10_000 default,
    // so that a future `config:publish` regenerating this file — which would
    // restore that default — turns this red rather than silently
    // reintroducing the outage.
    expect($maxRequest)->toBeGreaterThan(10_000)
        ->and($maxMessage)->toBeGreaterThan(10_000);
});
