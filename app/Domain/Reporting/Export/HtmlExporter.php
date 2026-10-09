<?php

namespace App\Domain\Reporting\Export;

use App\Domain\Reporting\Money;
use App\Domain\Reporting\ReportOptions;

/**
 * The report as one self-contained HTML file (W74 item 25).
 *
 * **Self-contained is the requirement, not a nicety.** The CSP rule in
 * CLAUDE.md — "nothing loads from a CDN, everything self-hosted" — binds a
 * file that leaves the server at least as hard as a page served by it: this
 * one is opened from a mail attachment or a shared drive, on a machine this
 * deployment has never met, possibly years later. So: no stylesheet link, no
 * font URL, no image URL, no script tag at all. Everything is inline and the
 * file renders identically offline.
 *
 * **Nothing is rendered as markup.** Every title, description, rationale and
 * paragraph of the written summary goes through `htmlspecialchars`. Node
 * text is user input, and the written summary may be model-drafted text a
 * person accepted (W54) — CLAUDE.md is explicit that no HTML renderer exists
 * in this codebase for generated output and that one must not be
 * reintroduced. This class writes HTML; it never renders any.
 *
 * The print rules are the same ones `resources/css/print.css` applies to the
 * page — repeated table headers, no break inside a row, expanded link URLs —
 * because a reader who opens this file and presses Ctrl-P should get the
 * document the report page prints, not a default browser dump.
 */
final class HtmlExporter
{
    /**
     * @param  array<string, mixed>  $report  the whole of ReportAssembler::assemble()
     */
    public function export(array $report, ReportOptions $options): string
    {
        $sections = (new ReportSectionBuilder)->build($report, $options);
        $money = new Money($this->currency($report));
        $title = $this->documentTitle($report);

        $html = '<!DOCTYPE html>'."\n";
        $html .= '<html lang="en"><head><meta charset="utf-8">';
        $html .= '<meta name="viewport" content="width=device-width, initial-scale=1">';
        $html .= '<title>'.$this->e($title).'</title>';
        $html .= '<style>'.$this->css().'</style>';
        $html .= '</head><body><main>';
        $html .= '<h1>'.$this->e($title).'</h1>';

        foreach ($sections as $section) {
            $html .= '<section id="'.$this->e($section->key).'">';
            $html .= '<h2>'.$this->e($section->title).'</h2>';

            foreach ($section->prose as $paragraph) {
                $html .= '<p>'.$this->e($paragraph).'</p>';
            }

            if ($section->hasTable()) {
                $html .= $section->rows === []
                    ? '<p class="empty">Nothing in this section.</p>'
                    : $this->table($section, $money);
            }

            $html .= '</section>';
        }

        return $html.'</main></body></html>';
    }

    private function table(ReportSection $section, Money $money): string
    {
        $html = '<table><thead><tr>';
        foreach ($section->headers as $header) {
            $html .= '<th>'.$this->e($header).'</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($section->rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                // Numbers right-aligned and tabular, text left: a column of
                // currency that does not line up on the decimal point is a
                // column a reader cannot scan.
                $class = $cell->kind === Cell::TEXT ? '' : ' class="num"';
                $html .= '<td'.$class.'>'.$this->e($cell->display($money)).'</td>';
            }
            $html .= '</tr>';
        }

        return $html.'</tbody></table>';
    }

    /** @param array<string, mixed> $report */
    private function documentTitle(array $report): string
    {
        $cover = is_array($report['cover'] ?? null) ? $report['cover'] : [];
        $name = is_string($cover['title'] ?? null) && $cover['title'] !== ''
            ? $cover['title']
            : 'Assessment';

        return 'ArgueSecure — '.$name.' — '.now()->format('Y-m-d');
    }

    /** @param array<string, mixed> $report */
    private function currency(array $report): string
    {
        $assessment = is_array($report['assessment'] ?? null) ? $report['assessment'] : [];

        return is_string($assessment['currency'] ?? null) && $assessment['currency'] !== ''
            ? $assessment['currency']
            : 'EUR';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Light-mode colours only, and stated rather than inherited. The page
     * this was exported from is theme-aware; a file on someone else's disk
     * has no theme to be aware of, and a document that renders white-on-white
     * because the recipient's reader defaults to dark is a document that did
     * not arrive.
     */
    private function css(): string
    {
        return <<<'CSS'
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #ffffff;
            color: #111827;
            font: 14px/1.5 ui-sans-serif, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        main { max-width: 60rem; margin: 0 auto; padding: 2rem 1.5rem 4rem; }
        h1 { font-size: 1.6rem; margin: 0 0 2rem; }
        h2 { font-size: 1.1rem; margin: 0 0 .5rem; }
        section { margin: 0 0 2.5rem; }
        p { margin: 0 0 .6rem; color: #374151; }
        p.empty { color: #6b7280; font-style: italic; }
        table { border-collapse: collapse; width: 100%; margin-top: .75rem; font-size: 12px; }
        th, td { border: 1px solid #d1d5db; padding: .35rem .5rem; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; font-weight: 600; }
        td.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        tbody tr:nth-child(even) { background: #f9fafb; }
        @page { margin: 18mm 15mm; }
        @media print {
            body { background: #ffffff; color: #000000; }
            main { max-width: none; padding: 0; }
            section { break-inside: auto; page-break-inside: auto; }
            h2 { break-after: avoid; page-break-after: avoid; }
            thead { display: table-header-group; }
            tr { break-inside: avoid; page-break-inside: avoid; }
            a[href]::after { content: " (" attr(href) ")"; font-size: 90%; }
        }
        CSS;
    }
}
