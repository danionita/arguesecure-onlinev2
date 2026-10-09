<?php

// Application-specific configuration that does not belong in any of
// Laravel's own config/*.php files. `env()` is permitted here and nowhere
// else outside a config file (CLAUDE.md: "env() outside a config file
// returns null once config:cache runs"), though nothing under this key
// currently needs it.
//
// References (schema.md §3.12, PLAN.md Phase 8 item 5): the per-kind
// validation pattern only. There is deliberately no link-template string
// here — App\Domain\References\ReferenceValidator::deriveUrl() hardcodes
// each derived kind's URL shape, because `attack_technique` needs to
// branch on whether a sub-technique suffix is present (T1566 vs
// T1566.001), which a single placeholder template cannot express. No
// outbound API call is ever made — every link is a pure string built from
// the stored identifier, nothing is fetched or enriched.
return [
    // Scale calibration (W65, schema.md §3.2.1). The one number in the
    // default-representative rule that the spec itself marks configurable.
    'scales' => [
        // The open top band has no upper bound, so its geometric mean is
        // undefined; the spec's answer is `lower × 3`. Deployments assessing
        // catastrophic loss may want a longer tail. Only ever a *pre-fill* —
        // every generated representative is editable, and invariant 5 is
        // re-validated on save whatever the number came from.
        'top_band_multiplier' => 3,
    ],

    'references' => [
        'kinds' => [
            'cve' => [
                'label' => 'CVE',
                'pattern' => '/^CVE-\d{4}-\d{4,}$/',
            ],
            'cwe' => [
                'label' => 'CWE',
                'pattern' => '/^CWE-\d{1,5}$/',
            ],
            'capec' => [
                'label' => 'CAPEC',
                'pattern' => '/^CAPEC-\d{1,4}$/',
            ],
            'attack_technique' => [
                'label' => 'MITRE ATT&CK technique',
                'pattern' => '/^T\d{4}(\.\d{3})?$/',
            ],
            // A framework control a defence implements (PLAN-2.7 D64):
            // `FRAMEWORK:CONTROL`, the framework from `frameworks` below and
            // the control part checked against that framework's pattern by
            // App\Domain\References\ControlId, so no single pattern here.
            'control' => [
                'label' => 'Control',
                'pattern' => null,
            ],
            // Identifier is unused for `url` — the URL itself *is* the
            // input (schema.md §3.12) — so `pattern` here validates the
            // `url` field, not an `identifier`. Restricted to http(s) only,
            // never javascript:/data:/file:/etc.
            'url' => [
                'label' => 'URL',
                'pattern' => '#^https?://#i',
            ],
            // No pattern: `other` accepts any non-blank free-text
            // identifier alongside its own user-supplied URL.
            'other' => [
                'label' => 'Other',
                'pattern' => null,
            ],
        ],

        // Surfaced prominently in the UI; every other kind sits behind a
        // "more" affordance (schema.md §3.12's last line).
        'primary_kind' => 'cve',

        // The frameworks a `control` reference may cite (D64). A closed list
        // is what makes the citation structured; adding a framework is a
        // config change, and a code here is never renamed once an
        // assessment may cite it. `pattern` matches the control part after
        // the colon, already uppercased. `url` is the framework's own page:
        // the standards' text is not free to deep-link, so no control gets a
        // link of its own. `standard-*.json` cites these codes.
        'frameworks' => [
            'ISO27001-2022' => [
                'label' => 'ISO/IEC 27001:2022',
                'pattern' => '/^(A\.[5-8]\.\d{1,2}|(4|5|6|7|8|9|10)(\.\d{1,2}){1,2})$/',
                'url' => 'https://www.iso.org/standard/82875.html',
            ],
            'ISO42001-2023' => [
                'label' => 'ISO/IEC 42001:2023',
                'pattern' => '/^(A\.\d{1,2}\.\d{1,2}(\.\d{1,2})?|(4|5|6|7|8|9|10)(\.\d{1,2}){1,2})$/',
                'url' => 'https://www.iso.org/standard/81230.html',
            ],
            'EUAIACT' => [
                'label' => 'EU AI Act',
                'pattern' => '/^(ART\.\d{1,3}(\(\d{1,2}\))?(\([A-Z]{1,2}\))?|ANNEX\.[IVX]{1,5})$/',
                'url' => 'https://eur-lex.europa.eu/eli/reg/2024/1689/oj',
            ],
            'GDPR' => [
                'label' => 'GDPR',
                'pattern' => '/^ART\.\d{1,2}(\(\d{1,2}\))?(\([A-Z]\))?$/',
                'url' => 'https://eur-lex.europa.eu/eli/reg/2016/679/oj',
            ],
            'NIS2' => [
                'label' => 'NIS2',
                'pattern' => '/^ART\.\d{1,2}(\(\d{1,2}\))?(\([A-Z]\))?$/',
                'url' => 'https://eur-lex.europa.eu/eli/dir/2022/2555/oj',
            ],
            'SOC2' => [
                'label' => 'SOC 2 Trust Services Criteria',
                'pattern' => '/^(CC[1-9]|A1|C1|PI1|P[1-8])\.\d{1,2}$/',
                'url' => 'https://www.aicpa-cima.com/resources/download/2017-trust-services-criteria-with-revised-points-of-focus-2022',
            ],
            'CIS8' => [
                'label' => 'CIS Controls v8.1',
                'pattern' => '/^\d{1,2}(\.\d{1,2})?$/',
                'url' => 'https://www.cisecurity.org/controls/v8-1',
            ],
        ],
    ],

    // Where the *browser* dials Reverb, shared onto every Inertia page by
    // HandleInertiaRequests (W27 defect 1). Distinct from
    // `broadcasting.connections.reverb.options`, which is where the *server*
    // posts broadcasts to — in Docker that is the internal `reverb` service,
    // which no browser can resolve.
    //
    // All three are null by default and mean "use the page's own origin",
    // because nginx proxies /app to Reverb (deployment.md §2.1) so the
    // websocket inherits the app's hostname, port and TLS whatever they are.
    // That is what makes one image deployable anywhere. Set these only for a
    // proxy-less local run where `reverb:start` is reachable on its own port.
    'reverb_client' => [
        'host' => env('REVERB_CLIENT_HOST'),
        'port' => env('REVERB_CLIENT_PORT'),
        'scheme' => env('REVERB_CLIENT_SCHEME'),
    ],

    /*
     * The currencies an assessment may be denominated in (W44, item 12).
     *
     * **Display only** (PLAN-2.2.md D14). Choosing one changes the symbol and
     * the grouping `Intl.NumberFormat` produces and nothing else: no
     * conversion, no exchange rates, no stored second figure. An assessment
     * whose currency is changed from EUR to JPY still holds exactly the
     * numbers someone typed — they are simply now labelled ¥. That is the
     * honest behaviour: this application has no business inventing a rate,
     * and a converted figure that drifts with the market would make two
     * readings of the same assessment disagree.
     *
     * A fixed list, never a free string. `Intl.NumberFormat` throws a
     * RangeError on an unknown code, which would blank every figure on the
     * page, and the value reaches the browser — so an unvalidated one is a
     * stored input rendered into a formatter's options.
     *
     * ISO 4217 alpha-3, upper case, as the `char(3)` column stores them.
     * Deliberately short: these are the currencies this tool is actually used
     * in, and a picker of 180 entries is worse for everyone than a list of
     * ten plus a request to add one.
     */
    'currencies' => [
        'EUR' => 'Euro',
        'USD' => 'US dollar',
        'GBP' => 'Pound sterling',
        'CHF' => 'Swiss franc',
        'SEK' => 'Swedish krona',
        'NOK' => 'Norwegian krone',
        'DKK' => 'Danish krone',
        'PLN' => 'Polish złoty',
        'CAD' => 'Canadian dollar',
        'AUD' => 'Australian dollar',
        'JPY' => 'Japanese yen',
    ],

    /*
     * Longest period a capital cost may be spread over (W44).
     *
     * `assessments.amortization_years` is an unsigned tinyint, so the column
     * itself tops out at 255; ten is the point past which the figure stops
     * meaning anything — a control amortised over a working lifetime longer
     * than the assessment will be reviewed in produces an annual cost that
     * nobody can check.
     */
    'max_amortization_years' => 10,
];
