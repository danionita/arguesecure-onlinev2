<?php

namespace App\Http\Middleware;

use App\Models\Assessment;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sharing means nothing for a template (W141, T2): it has no owner to hand it
 * over from, and a collaborator, a public flag or a share link would put an
 * instance-wide reference object in front of people the library already
 * reaches through `useAsTemplate`. 403 for everyone, an admin included, so no
 * future sharing route is one `manage` check away from a template.
 *
 * Resolves the assessment itself rather than trusting the binding to have run
 * first, because route middleware order against SubstituteBindings is not
 * something a security guard should depend on.
 */
class RejectTemplate
{
    public function handle(Request $request, Closure $next): Response
    {
        $assessment = $request->route('assessment');

        if (is_string($assessment)) {
            $assessment = Assessment::query()->where('uuid', $assessment)->first();
        }

        abort_if($assessment instanceof Assessment && $assessment->is_template, 403);

        return $next($request);
    }
}
