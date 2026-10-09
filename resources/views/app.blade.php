<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        {{-- W41: the theme, before the first paint.

             Everything else about the theme lives in
             resources/js/Components/UI/useTheme.ts, but no module script can
             run before the browser paints the empty document — so a user who
             had chosen dark against a light OS got a white flash on every
             full page load. This is the only code that can run early enough.

             `system` needs nothing here: app.css scopes its
             prefers-color-scheme block to `:root:not(.light):not(.dark)`, so
             the absence of a class already means "follow the OS".

             Nonce'd, like @routes below, so the enforced CSP stays enforced
             (security.md §6: nothing inline without a nonce, nothing from a
             CDN). The storage key is duplicated from useTheme.ts's
             THEME_STORAGE_KEY on purpose — importing it here is impossible —
             and the two must stay in agreement. --}}
        <script nonce="{{ Illuminate\Support\Facades\Vite::cspNonce() }}">
            try {
                var t = localStorage.getItem('arguesecure.theme');
                if (t === 'light' || t === 'dark') {
                    document.documentElement.classList.add(t);
                }
            } catch (e) {
                // Storage can throw outright in a locked-down browser.
                // Falling through leaves the OS preference in charge, which
                // is the correct default anyway.
            }
        </script>

        <!-- Scripts -->
        {{-- Ziggy's directive takes the nonce as its second argument and
             emits none when omitted; it does not fall back to
             Vite::cspNonce(). Without it this one inline script — the route
             table every route() call site depends on — is the only thing
             blocking an enforced CSP. --}}
        @routes(null, Illuminate\Support\Facades\Vite::cspNonce())
        @vite(['resources/js/app.ts', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
