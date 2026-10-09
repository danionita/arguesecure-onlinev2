<?php

namespace App\Domain\Help;

use Illuminate\Support\Str;

/**
 * Renders a help page's markdown to sanitized HTML (PLAN.md Phase 9 item 2:
 * "Author it as markdown files rendered by the app so updating help is not
 * a code deploy").
 *
 * No new dependency: `league/commonmark` is already a hard requirement of
 * `laravel/framework` itself (it backs `Illuminate\Support\Str::markdown()`,
 * used below), so adding a markdown package here would duplicate one
 * already in composer.lock. Deliberately not `laravelcollective/html`,
 * `dompdf`, or anything else on CLAUDE.md's "never reintroduce" list.
 *
 * Both options below are CommonMark's own documented untrusted-input
 * configuration (vendor/league/commonmark/README.md "Safety" section), and
 * both differ from the library's own defaults, which is exactly why they
 * are set explicitly rather than left implicit:
 *
 *   - `html_input => strip` removes raw HTML the markdown source contains
 *     instead of passing it through — the library defaults to `allow`.
 *   - `allow_unsafe_links => false` drops `javascript:`/`vbscript:`/`data:`
 *     (etc.) URIs from generated `href`/`src` attributes — the library
 *     defaults to `true`.
 *
 * Help content is authored by whoever owns this repository, not by an
 * assessment's end users, so nothing here is reachable by request input
 * today. Both options are still set because the brief is explicit that the
 * renderer "must not become an XSS vector if help is ever made
 * user-editable" — this is what makes that true independent of trusting
 * every future author of a help page, not only the current ones.
 */
final class MarkdownRenderer
{
    public static function toHtml(string $markdown): string
    {
        return Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }
}
