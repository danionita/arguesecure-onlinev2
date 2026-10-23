<?php

namespace App\Http\Middleware;

use App\Domain\Release\ReleaseInfo;
use App\Models\Assessment;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;
use Symfony\Component\HttpFoundation\Response;

class HandleInertiaRequests extends Middleware
{
    /** A sidebar group is scanned at a glance; the full list is one click away. */
    private const NAV_LIMIT = 10;

    /**
     * The session flash keys a controller writes with `->with(…)` that the
     * shared chrome shows as a toast (W115). An allowlist, not the whole bag:
     * the bag also carries `restore`, `status` and `shareTokenAssessmentId`,
     * which are data for one page rather than sentences for a person.
     */
    private const FLASH_KEYS = ['success', 'error'];

    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * W115: what a controller flashed with `->with('success', …)` reaches the
     * page, as Inertia's own flash data rather than as a shared prop.
     *
     * Until W115 nothing shared the flash bag, so every `->with('success', …)`
     * stopped in the server-side session: "Version saved.", the restore
     * summary, a restore that failed and why, "Saved as a template." — all
     * written, all discarded (the `DEFECT:` pin in `ScaleResetTest`, now a
     * `FIXED:` guard).
     *
     * Flash data rather than the pin's suggested `'flash'` prop, for one
     * reason a prop cannot meet: Inertia keeps a page's props in the
     * browser's history state, so a prop-borne "Version saved." would toast
     * again every time someone pressed Back to that page. Flash data is
     * delivered with exactly one response and stripped from history
     * (inertia-laravel's `Response::resolveFlashData()` pulls it from the
     * session; the client stores history with `flash: {}`), and the client
     * fires one `flash` event per response that carries it — which is the
     * "render it once" the pin asked for, decided by the library rather than
     * by a layout guessing which visits are new.
     *
     * The values are read here, *before* the controller runs, because that is
     * when Laravel's flash bag holds what the previous request wrote. Only
     * GETs: a page is rendered on a GET, and the request a redirect leads to
     * is one. Anything else would move the message off the page it was
     * written for. Reading does not consume Laravel's copy, so W87's two
     * controllers that pass `session('success')` as their own prop still see
     * it.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only a request that will render a page may take the message: a
        // notification poll or a download between the redirect and the page
        // would otherwise consume it.
        if ($request->isMethod('GET') && $request->hasSession() && ($request->header('X-Inertia') || ! $request->expectsJson())) {
            $flashed = array_filter(
                $request->session()->only(self::FLASH_KEYS),
                fn (mixed $value): bool => is_string($value) && $value !== '',
            );

            if ($flashed !== []) {
                Inertia::flash($flashed);
            }
        }

        return parent::handle($request, $next);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            'nav' => $this->nav($request),
            'reverb' => $this->reverb(),
        ];
    }

    /**
     * What the sidebar needs, on every authenticated page (W41, ui.md §2).
     *
     * The sidebar replaces a top bar whose only navigation was a product
     * mark and a user menu — everything else was a search box that searched
     * the assessment you were not on. It lists real destinations, so it
     * needs real data on every request, which is the cost being accepted
     * here deliberately rather than by accident:
     *
     *  - **Two queries, both capped.** Assessments are eager-loaded with
     *    `rootNode` (titles live there, schema.md) and `owner`, because the
     *    shared list names who shared it. Without the eager load this is the
     *    N+1 that CLAUDE.md names as the legacy tree render's defining
     *    defect, on every page in the application.
     *  - **Capped at ten, with the full list one click away.** A sidebar is
     *    scanned, not read, and someone with 400 assessments does not want
     *    them in a rail.
     *
     * Templates are *not* listed here. `templates.index` already applies its
     * own visibility rules (W20) and a template list is neither short nor
     * personal; the sidebar links to that page instead of previewing it.
     *
     * `is_admin` decides whether the Admin item renders. It is not the
     * guard — `EnsureUserIsAdmin` is, and the route still 403s for anyone
     * who forges this prop or types the URL. Hiding a link the user cannot
     * follow is courtesy, not authorization, and W41's tests assert both
     * halves separately so nobody can mistake one for the other.
     *
     * `currentAssessment` is gone as of W82 (D41): Settings and History left
     * this prop's only readers — `Sidebar.vue` gated both on it — for
     * `Toolbar/AssessmentActions.vue`, which reads `store.abilities` off the
     * page's own props instead. A prop nothing reads is exactly the dead
     * control CLAUDE.md's `ReportOptions::$figures` write-up warns can
     * survive a consolidation for releases at a time; this one is removed
     * rather than left computing a query on every request inside an
     * assessment for nobody.
     *
     * @return array<string, mixed>
     */
    private function nav(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return ['authenticated' => false];
        }

        $columns = fn (Assessment $assessment): array => [
            'uuid' => $assessment->uuid,
            'title' => $assessment->title ?? 'Untitled assessment',
        ];

        $mine = Assessment::query()
            ->with('rootNode')
            ->where('owner_user_id', $user->id)
            ->latest('updated_at')
            ->limit(self::NAV_LIMIT)
            ->get();

        $shared = Assessment::query()
            ->with(['rootNode', 'owner'])
            ->where('owner_user_id', '!=', $user->id)
            ->whereHas('members', fn ($member) => $member->where('users.id', $user->id))
            ->latest('updated_at')
            ->limit(self::NAV_LIMIT)
            ->get();

        return [
            'authenticated' => true,
            'isAdmin' => (bool) $user->is_admin,
            'myAssessments' => $mine->map($columns)->values(),
            'sharedWithMe' => $shared->map(fn (Assessment $assessment): array => [
                ...$columns($assessment),
                // Display name only. An assessment list is a page about
                // other people's work, which is exactly where an address
                // leaks — the same rule AssessmentLifecycleController::index
                // already follows.
                'ownerName' => $assessment->owner?->name,
            ])->values(),
            // W47. One indexed count per authenticated request — the badge is
            // on every page, so it is a shared prop rather than a fetch the
            // sidebar makes on mount, which would be the same query plus a
            // round trip and a frame of the badge showing the wrong number.
            // `notifications_unread_index` is what keeps it a seek.
            'notificationCount' => $user->unreadNotifications()->count(),
            // W124. Authenticated pages only: a sign-in page does not tell a
            // stranger which release is running.
            'version' => ReleaseInfo::for(config('arguesecure.version')),
        ];
    }

    /**
     * W27 defect 1: the browser's Reverb credentials, delivered at request
     * time rather than baked into the bundle.
     *
     * `useRealtime.ts` used to read `import.meta.env.VITE_REVERB_APP_KEY`,
     * which Vite substitutes when the image is **built** — but
     * `docker/entrypoint.sh` generates the Reverb credentials when the
     * container first **starts**, so the key did not exist at the only
     * moment it could have been baked in. Every deployed bundle shipped an
     * empty key, `connect()` returned immediately, and real-time
     * collaboration was inert everywhere.
     *
     * A shared Inertia prop is the only fix that keeps deployment.md §6's
     * "credentials are generated on first run and never committed" intact
     * while letting one image run in any deployment. The app key is a
     * public identifier by design — it appears in the WebSocket URL that
     * every client dials — so exposing it here leaks nothing; the secret
     * stays server-side and is never shared.
     *
     * `host`/`port`/`scheme` are deliberately null unless overridden: the
     * client connects to its own origin, because nginx proxies `/app` to
     * the internal-only `reverb` container (deployment.md §2.1). Only a
     * proxy-less local run (`php artisan serve` + `reverb:start`) needs to
     * point somewhere else, which is what the env overrides are for.
     *
     * @return array<string, mixed>
     */
    private function reverb(): array
    {
        return [
            'key' => (string) config('broadcasting.connections.reverb.key'),
            'host' => config('arguesecure.reverb_client.host'),
            'port' => config('arguesecure.reverb_client.port'),
            'scheme' => config('arguesecure.reverb_client.scheme'),
        ];
    }
}
