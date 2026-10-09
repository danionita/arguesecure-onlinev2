<?php

use App\Models\Assessment;
use App\Models\User;

function dumpHeaders(string $label, $r): void
{
    $h = $r->headers;
    fwrite(STDERR, sprintf(
        "%s status=%d nosniff=%s csp=%s cspro=%s\n",
        $label,
        $r->getStatusCode(),
        var_export($h->get('X-Content-Type-Options'), true),
        var_export($h->get('Content-Security-Policy') !== null, true),
        var_export($h->get('Content-Security-Policy-Report-Only') !== null, true),
    ));
}

it('probe error responses', function () {
    dumpHeaders('unmatched-404', $this->get('/no-such-page'));
    dumpHeaders('guest-redirect ', $this->get('/dashboard'));

    $user = User::factory()->create();
    $other = Assessment::factory()->create();
    dumpHeaders('policy-403     ', $this->actingAs($user)->get('/assessments/'.$other->uuid));

    dumpHeaders('model-404      ', $this->actingAs($user)->get('/assessments/00000000-0000-0000-0000-000000000000'));
    dumpHeaders('health /up     ', $this->get('/up'));

    expect(true)->toBeTrue();
});
