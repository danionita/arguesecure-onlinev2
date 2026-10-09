<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Page components
    |--------------------------------------------------------------------------
    |
    | This file exists for one reason: inertia-laravel's own default page path
    | is `resource_path('js/pages')`, lowercase, while this application uses
    | `resources/js/Pages` — capitalised, matching Components/ and Layouts/
    | and the import paths in every .vue file and in resources/js/app.ts.
    |
    | On a case-insensitive filesystem (Windows, macOS) those are the same
    | directory and the mismatch is invisible. On Linux they are not, so
    | `inertia.view-finder` resolved nothing in CI and every
    | `assertInertia(...)->component(...)` failed with "page component file
    | does not exist" — nine tests, red on every push from W12 onward while
    | the identical run was green locally.
    |
    | The application itself was never affected: app.ts resolves pages through
    | its own explicit glob rooted at `./Pages`, and `pages.ensure_pages_exist`
    | is false at runtime, so the view finder is only ever consulted by the
    | test assertion. It is still worth pinning here rather than renaming the
    | directory, which would rewrite the import path of every page in the
    | codebase.
    |
    | The whole `pages` block is repeated here because ServiceProvider merges
    | package config with a shallow array_merge: supplying only `paths` would
    | drop `extensions` and break resolution a second, less obvious way.
    |
    */

    'pages' => [

        'ensure_pages_exist' => false,

        'paths' => [

            resource_path('js/Pages'),

        ],

        'extensions' => [

            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',

        ],

    ],

];
