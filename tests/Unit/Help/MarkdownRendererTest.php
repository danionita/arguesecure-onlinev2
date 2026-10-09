<?php

use App\Domain\Help\MarkdownRenderer;

/*
 * W23 brief: "If you add a renderer, a test that a script tag or
 * `javascript:` URL in markdown does not survive rendering." Help content
 * is authored by whoever owns this repository today, but
 * MarkdownRenderer's own docblock is explicit that the config is set so
 * this stays true "if help is ever made user-editable" — these assertions
 * are what make that claim checked rather than assumed.
 */

test('a raw script tag in markdown is not rendered as an executable script tag', function () {
    $html = MarkdownRenderer::toHtml("Hello\n\n<script>alert('xss')</script>\n\nWorld");

    expect($html)->not->toContain('<script>');
});

test('a javascript: URL link does not survive as a javascript: href', function () {
    $html = MarkdownRenderer::toHtml('[click me](javascript:alert(1))');

    expect($html)->not->toContain('javascript:');
});

test('a javascript: URL image src does not survive as a javascript: src', function () {
    $html = MarkdownRenderer::toHtml('![x](javascript:alert(1))');

    expect($html)->not->toContain('javascript:');
});

test('an inline HTML event handler attribute does not survive rendering', function () {
    $html = MarkdownRenderer::toHtml('Hello <img src=x onerror="alert(1)"> World');

    expect($html)->not->toContain('onerror');
});

test('ordinary markdown still renders to the expected HTML elements', function () {
    $html = MarkdownRenderer::toHtml("# Title\n\nA **bold** paragraph with a [safe link](https://example.com).");

    expect($html)->toContain('<h1>Title</h1>')
        ->toContain('<strong>bold</strong>')
        ->toContain('href="https://example.com"');
});
