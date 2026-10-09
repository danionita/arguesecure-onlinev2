import {
    expect,
    test,
    type APIResponse,
    type Download,
    type Page,
} from '@playwright/test';
import { readFileSync } from 'node:fs';
import { apiRequest } from './support/api';
import { login } from './support/auth';
import { adminEmail, adminPassword } from './support/env';
import { openAssessment } from './support/tree';

/**
 * This file's tests get longer than the 30s default, and the reason is the
 * two retry helpers below rather than anything slow.
 *
 * `throttle:reports` is 10/min per user, and both helpers wait out the
 * server's own `Retry-After` — up to 60s — before retrying. That wait cannot
 * fit inside a 30s test, so a test that hit the limit failed *in the retry*,
 * reporting a timeout rather than the 429 it was handling correctly. W74 made
 * this reachable by adding five export formats to a file that already made
 * about a dozen requests against the same bucket.
 *
 * Raising the per-test budget here keeps the rate limit exactly as
 * security.md §9 specifies — the guard is not weakened, and the assertions
 * still demand a real 200 with real content. It only stops the mitigation
 * being cut off half way through.
 */
test.describe.configure({ timeout: 90_000 });

/**
 * Fetches a report route, waiting out the rate-limit window once if the
 * bucket is already spent.
 *
 * security.md's table sets report generation at 10/min per user and that is
 * the right number for a person: viewing a report, changing its options and
 * downloading it is a handful of requests. This *file* makes about a dozen
 * in well under a minute, because it is eight assertions about the same
 * document — so the exports at the end run into a limit no user would. The
 * limit is not the defect and neither is the test; retrying once on the
 * server's own `Retry-After` keeps the assertion (a real 200 with real
 * content) intact without weakening the guard or sleeping unconditionally.
 */
async function fetchReport(page: Page, url: string): Promise<APIResponse> {
    const first = await page.request.get(url);
    if (first.status() !== 429) return first;

    const retryAfter = Number(first.headers()['retry-after'] ?? 60);
    await page.waitForTimeout(
        (Number.isFinite(retryAfter) ? retryAfter : 60) * 1000 + 1000,
    );

    return page.request.get(url);
}

/**
 * Opens the export menu and clicks an item, returning the download.
 *
 * The same courtesy as `gotoReport`, for the one request in this file that a
 * *click* makes. The item is a plain link to a throttled route, so the
 * request after the tenth in a minute answers 429 with an HTML page — the
 * browser navigates to it and the download this waits for never starts.
 * `gotoReport` cannot see that coming: its own visit is the tenth and
 * succeeds. So a 429 page here is waited out and the click repeated.
 */
async function clickDownload(
    page: Page,
    uuid: string,
    item: RegExp,
): Promise<Download> {
    for (let attempt = 0; ; attempt++) {
        await page.getByRole('button', { name: 'Export' }).click();
        const download = page.waitForEvent('download');
        await page.getByRole('menuitem', { name: item }).click();

        const limited = page
            .getByRole('heading', { name: '429' })
            .waitFor()
            // Still pending when the download wins; it must not surface
            // as an unhandled rejection once the page closes.
            .then(
                () => null,
                () => new Promise<never>(() => undefined),
            );
        const outcome = await Promise.race([download, limited]);
        if (outcome !== null) return outcome;
        if (attempt > 0) throw new Error('Still rate-limited after waiting.');

        download.catch(() => undefined);
        await page.waitForTimeout(61_000);
        await gotoReport(page, uuid);
    }
}

/**
 * The same courtesy for a page navigation.
 *
 * This file visits `/report` nine times, and `throttle:reports` is 10/min per
 * user (security.md §9). Whether the ninth lands inside the same window as
 * the first depends on how fast the rest of the suite ran — which is exactly
 * the kind of test that passes for months and then fails on a faster machine.
 * It did, once the suite dropped from 4.9 to 3.6 minutes.
 *
 * The limit is spec-correct and stays; the suite waits it out, exactly as
 * `fetchReport` already does for the export endpoints. Retrying on the
 * server's own `Retry-After` rather than sleeping unconditionally keeps the
 * run fast in the ordinary case.
 */
async function gotoReport(page: Page, uuid: string, query = ''): Promise<void> {
    const url = `/assessments/${uuid}/report${query}`;
    const response = await page.goto(url);

    if (response?.status() !== 429) return;

    const retryAfter = Number(response.headers()['retry-after'] ?? 60);
    await page.waitForTimeout(
        (Number.isFinite(retryAfter) ? retryAfter : 60) * 1000 + 1000,
    );

    await page.goto(url);
}

/**
 * Scenario 8 of PLAN.md's end-to-end list: "Generate the report; confirm
 * current and target states differ correctly, the ranked defence list is
 * ordered by marginal contribution, cost buckets are separated, and Save as
 * PDF produces a clean, correctly paginated document."
 *
 * The last clause is the browser's own print pipeline, deliberately: PLAN.md
 * Phase 7 chose a tuned `@media print` stylesheet over a headless-Chromium
 * sidecar, partly for resource cost and partly to keep `dompdf 0.6` — the
 * most dangerous dependency in the legacy repo — permanently gone. What can
 * be asserted here is that the button exists and triggers the print
 * pipeline; what the operating system's print dialog then produces is
 * outside any browser automation.
 *
 * The figures are the same hand calculation as scenario 4, so a report that
 * disagreed with the editor would show up as a mismatch against numbers
 * derived independently of both.
 */

const MFA = 'Multi-factor authentication';
const WAF = 'Web application firewall';

test.describe('the report', () => {
    test('the report renders the headline figures the editor shows', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        const uuid = await openAssessment(page);

        const response = await page.goto(`/assessments/${uuid}/report`);
        expect(response?.status()).toBe(200);

        // Same three baselines, same numbers as the tree editor's totals
        // panel — inherent 135,000, current 43,000, target 33,000.
        await expect(page.locator('body')).toContainText('€135,000');
        await expect(page.locator('body')).toContainText('€43,000');
        await expect(page.locator('body')).toContainText('€33,000');
    });

    test('current and target differ, and the difference is the todo defence', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        const uuid = await openAssessment(page);
        await gotoReport(page, uuid);

        // The distinction the three baselines exist for: MFA is implemented
        // so it is already in `current`; the WAF is todo so it only appears
        // in `target`, and the 10,000 gap between the two is exactly what
        // implementing it would buy. A report that showed the same figure
        // for both would make the roadmap section meaningless.
        await expect(page.locator('body')).toContainText(MFA);
        await expect(page.locator('body')).toContainText(WAF);
    });

    test('the ranked defence list separates implemented from todo', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        const uuid = await openAssessment(page);
        await gotoReport(page, uuid);

        // PLAN.md Phase 7 items 4 and 5: implemented controls ranked by
        // marginal contribution, and todo controls ranked by the opportunity
        // implementing them would buy — two separate sections, because they
        // answer two different questions.
        await expect(
            page
                .getByRole('heading', { name: /importance|implemented/i })
                .first(),
        ).toBeVisible();
        await expect(
            page
                .getByRole('heading', { name: /opportunity|roadmap|todo/i })
                .first(),
        ).toBeVisible();
    });

    test('a print button drives the browser print pipeline', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        const uuid = await openAssessment(page);
        await gotoReport(page, uuid);

        // W35 renamed this from "Download PDF" to "Print as configured";
        // W74/D35 shortened it to "Print". The qualifier existed to draw a
        // distinction against a **Download report** button beside it, and
        // that button is gone — the same forced-full render is now an item
        // in the export menu, named by what it produces. A button that
        // prints what is on screen needs no qualifier.
        //
        // W83 mounts `AssessmentActions.vue` (D40) for this authenticated
        // viewer, which sets `aria-label="Print report"` on the button
        // (`printLabel`, keyed on `view-mode="report"` — the same pattern
        // `ZoomControls.vue` uses for "Decrease chart size" vs "Decrease
        // text size"). The aria-label is the accessible name, so the exact
        // match moves from the visible text to it.
        const button = page.getByRole('button', {
            name: 'Print report',
            exact: true,
        });
        await expect(button).toBeVisible();

        // window.print() opens a native dialog that would hang the run, so
        // it is stubbed and its invocation observed. This asserts the wiring
        // — that the button reaches the print pipeline at all — which is the
        // part that can regress silently.
        await page.evaluate(() => {
            (window as unknown as { __printed: boolean }).__printed = false;
            window.print = () => {
                (window as unknown as { __printed: boolean }).__printed = true;
            };
        });
        await button.click();

        expect(
            await page.evaluate(
                () => (window as unknown as { __printed: boolean }).__printed,
            ),
        ).toBe(true);
    });

    test('the report is reachable from the editor, which is where a user is', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        const uuid = await openAssessment(page);

        // W33: the whole of W17 shipped behind a URL nothing linked to.
        // Reaching it by navigation is the point of this test — asserting the
        // page renders while only ever visiting it directly is what let that
        // ship in the first place.
        //
        // W35 moved it from a link in the page header into the third position
        // of the view switcher (PLAN-2.2.md D10), so this now presses the
        // control a user would actually press.
        await page.getByRole('radio', { name: 'Report' }).click();

        await expect(page).toHaveURL(
            new RegExp('/assessments/' + uuid + '/report$'),
        );
        await expect(
            // Exact, because the page carries a "Written summary" section
            // too and a substring match would resolve to both. They were both
            // called "Executive summary" until W67 — which is what this
            // selector caught.
            page.getByRole('heading', {
                name: 'Executive summary',
                exact: true,
            }),
        ).toBeVisible();
    });

    test('the switcher comes back, in the view that was clicked', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        const uuid = await openAssessment(page);
        await gotoReport(page, uuid);

        await expect(
            page.getByRole('radio', { name: 'Report' }),
        ).toHaveAttribute('aria-checked', 'true');

        // The report is a route, not a persisted editor mode: clicking List
        // must land in the list, not in whichever view the editor last had.
        await page.getByRole('radio', { name: 'List' }).click();

        await expect(page).toHaveURL(new RegExp('/assessments/' + uuid + '$'));
        await expect(page.getByRole('radio', { name: 'List' })).toHaveAttribute(
            'aria-checked',
            'true',
        );
    });

    /**
     * W83 replaces the open/close options panel with an always-visible row
     * beside the search box (brief item 5: "every option on one line,
     * always visible"). There is no "Report options" heading and no
     * open/close affordance any more — this test replaces
     * "the options panel can be closed, and says how to get it back",
     * which asserted exactly the behaviour removed.
     */
    test('the options row is always visible, beside the search box, with no open/close affordance', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        const uuid = await openAssessment(page);
        await gotoReport(page, uuid);

        await expect(
            page.getByRole('searchbox', { name: 'Search the assessment' }),
        ).toBeVisible();
        await expect(
            page.getByRole('radiogroup', { name: 'Baseline' }),
        ).toBeVisible();
        await expect(
            page.getByRole('radiogroup', { name: 'Shelved defences' }),
        ).toBeVisible();

        await expect(
            page.getByRole('button', { name: 'Report options' }),
        ).toHaveCount(0);
        await expect(
            page.getByRole('button', { name: 'Close report options' }),
        ).toHaveCount(0);

        // Still on screen after a reload — "always visible" is not a
        // per-session default that a stored "closed" preference could hide.
        await page.reload();
        await expect(
            page.getByRole('radiogroup', { name: 'Baseline' }),
        ).toBeVisible();
    });

    /**
     * D43. The report's zoom control is its own — not "nothing to zoom",
     * which was true only through W80 — and moving it must not touch the
     * chart/list's own stored zoom (contrast: two views sharing one number,
     * D9's remaining case for the chart and list themselves).
     */
    test("the report's zoom is its own and does not disturb the chart/list zoom", async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        const uuid = await openAssessment(page);

        // openAssessment() always leaves the editor in the list view (see
        // its own docblock in support/tree.ts) — every other scenario that
        // uses it reads the list's rows. This test needs the chart's own
        // zoom, so it switches explicitly rather than assuming the global
        // chart default survived a helper that deliberately overrides it.
        await page.getByRole('radio', { name: 'Chart' }).click();

        // Set a chart zoom first.
        await page.getByRole('button', { name: 'Increase chart size' }).click();
        const chartZoomAfterFirstClick = await page
            .getByTitle(/Reset chart size to 100%/)
            .textContent();

        await gotoReport(page, uuid);
        await expect(
            page.getByRole('group', { name: 'Zoom report' }),
        ).toBeVisible();
        await page
            .getByRole('button', { name: 'Increase report size' })
            .click();
        await page
            .getByRole('button', { name: 'Increase report size' })
            .click();

        // Back to the chart: its own zoom is untouched by the report's.
        await page.getByRole('radio', { name: 'Chart' }).click();
        await expect(page.getByTitle(/Reset chart size to 100%/)).toHaveText(
            chartZoomAfterFirstClick ?? '110%',
        );
    });

    test('the export menu still reaches everything expanded, in both states, with no options step', async ({
        page,
    }) => {
        // Long enough to wait out one rate-limit window; see `clickDownload`.
        test.setTimeout(180_000);

        await login(page, adminEmail, adminPassword());
        const uuid = await openAssessment(page);

        // The print dialog is native and would hang the run, so it is stubbed
        // before the page that auto-calls it ever loads.
        await page.addInitScript(() => {
            (window as unknown as { __printed: boolean }).__printed = false;
            window.print = () => {
                (window as unknown as { __printed: boolean }).__printed = true;
            };
        });

        // Renamed and repointed rather than deleted (W74/D35). "Download
        // report" the *button* is gone; the forced-full URL W33 defined is
        // not, and the behaviour this test pins — one click, no options step,
        // every section, both states — is still a requirement. It is reached
        // from the export menu now, where the same render is one of five
        // formats and is named by what it produces.
        await gotoReport(page, uuid);

        // W74 repointed this item at the HTML *export* route rather than at a
        // navigation to `report?download=1`. The forced-full contract is
        // unchanged — `download=1` still overrides every other parameter
        // server-side — but the response is now a file rather than a page, so
        // what this test reads is the downloaded document. Asserting on the
        // page after clicking would silently assert nothing at all: the
        // browser stays on the report, so every `toContainText` below would
        // have been answered by the page behind the menu.
        const download = await clickDownload(page, uuid, /Everything expanded/);

        expect(download.url()).toMatch(/download=1/);

        const path = await download.path();
        const html = readFileSync(path, 'utf8');

        // Both states, not whichever the last visit happened to leave behind.
        expect(html).toContain('€43,000');
        expect(html).toContain('€33,000');

        // Every section, and no options panel to be misread as the state of a
        // document that cannot be tuned from here.
        expect(html).toContain('The plan: cost and benefit');
        expect(html).toContain('Full assessment');
        expect(html).not.toContain('Report options');

        // Self-contained, per D34: a file that leaves the server is bound by
        // the same no-CDN rule as a page served by it.
        expect(html).not.toMatch(/<(script|link)[^>]+https?:\/\//);

        // The filename comes from the document title.
        expect(download.suggestedFilename()).toMatch(/\.html?$/);
        expect(html).toMatch(
            /<title>ArgueSecure — .+ — [0-9]{4}-[0-9]{2}-[0-9]{2}<\/title>/,
        );
    });

    /**
     * W74 item 15: "The view switcher must occupy the same screen position
     * across all three views, so switching is a change of content and not a
     * change of layout... either way, assert it — a pixel claim in prose is
     * not a guarantee."
     *
     * Here rather than in `tests/js` for the reason
     * `16-toolbar-one-row.spec.ts` gives for its own geometry assertions:
     * jsdom has no layout engine, `getBoundingClientRect()` is all zeroes,
     * and a switcher that had moved 200px would look identical to one that
     * had not.
     *
     * **Tightened in W81, which is when it became true.** W74 asserted the
     * vertical coordinate exactly and the horizontal one only as an anchor —
     * "within a hand's width of the right edge" — because the editor's row
     * carried the zoom controls *after* the switcher and the report has
     * nothing to zoom, so the two genuinely differed by that group's width.
     * W74 could not close it (it did not own `Components/Toolbar/Toolbar.vue`)
     * and refused to fake it with an empty spacer sized to the zoom group,
     * which would have asserted the fix rather than the property.
     *
     * W81 owns that file. The switcher now sits in the `anchor` slot of
     * `Components/Chrome/ChromeToolbarBand.vue`, which is **last** in the
     * right-hand group on both pages — the editor's zoom controls and, since
     * W83, the report's own (`ReportZoomControls.vue`, D43) sit before it on
     * their respective pages — so its distance from the right edge is the
     * band's padding and nothing else, for any siblings. That is a property,
     * not a measurement, and the exact equality below is now the honest
     * assertion.
     *
     * The horizontal measurement is against `documentElement.clientWidth`
     * rather than the 1280 viewport on purpose: the report is long enough to
     * scroll and the editor is not, so the two pages have different scrollbar
     * states, and `clientWidth` is the layout width the band's right edge
     * actually aligns to. Measuring against 1280 would compare the switcher's
     * position to a viewport edge that one of the two pages does not reach,
     * and fail on a scrollbar rather than on a chrome that had drifted.
     */
    test('the view switcher lands in the same place on the screen in all three views', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await login(page, adminEmail, adminPassword());
        const uuid = await openAssessment(page);

        const measure = async () => {
            const box = await page
                .getByRole('radio', { name: 'Report' })
                .boundingBox();
            expect(box).not.toBeNull();

            const clientWidth = await page.evaluate(
                () => document.documentElement.clientWidth,
            );

            return {
                centreY: Math.round(box!.y + box!.height / 2),
                height: Math.round(box!.height),
                fromRight: Math.round(clientWidth - (box!.x + box!.width)),
            };
        };

        // List and chart are the same page and the same toolbar, so measuring
        // both is measuring that the editor does not move the bar when the
        // view inside it changes.
        await page.getByRole('radio', { name: 'List' }).click();
        const list = await measure();

        await page.getByRole('radio', { name: 'Chart' }).click();
        const chart = await measure();

        await gotoReport(page, uuid);
        const report = await measure();

        expect(chart.centreY).toBe(list.centreY);
        expect(report.centreY).toBe(list.centreY);
        expect(report.height).toBe(list.height);

        // The horizontal half of item 15, closed in W81. Exactly the same
        // inset, not merely the same side of the window.
        expect(chart.fromRight).toBe(list.fromRight);
        expect(report.fromRight).toBe(list.fromRight);

        // ...and that inset is the toolbar band's own padding, so the three
        // agreeing cannot be three pages that all drifted the same way. The
        // band is `px-3` outside the row and the row `px-3` inside it, plus
        // the segmented control's own border and padding around the last
        // radio — comfortably under 40px, and nowhere near the 200 this could
        // pass at while sitting mid-row.
        expect(list.fromRight).toBeGreaterThan(0);
        expect(list.fromRight).toBeLessThan(40);
    });

    test('the plan summary states what the todo defence costs and what it buys', async ({
        page,
    }) => {
        await login(page, adminEmail, adminPassword());
        const uuid = await openAssessment(page);
        await gotoReport(page, uuid);

        const programme = page
            .locator('section', { hasText: 'The plan: cost and benefit' })
            .first();

        // The WAF is the only todo defence, and it moves the total from
        // 43,000 to 33,000 — so the plan buys 10,000 a year.
        await expect(programme).toContainText('€10,000');
        await expect(programme).toContainText('Return on the plan');
    });

    test('all five exports are downloadable, and none is a rename of another', async ({
        page,
    }) => {
        // Long enough to wait out one rate-limit window; see `fetchReport`.
        test.setTimeout(180_000);

        await login(page, adminEmail, adminPassword());
        const uuid = await openAssessment(page);

        // D34's five formats. Requested rather than navigated to: they all
        // send Content-Disposition: attachment, and `page.goto` on a download
        // aborts the navigation with "Download is starting" instead of
        // returning a response. `page.request` carries the page's cookies, so
        // this is still an authenticated fetch of the real route.
        const html = await fetchReport(
            page,
            `/assessments/${uuid}/report/export.html`,
        );
        expect(html.status()).toBe(200);
        expect(await html.text()).toContain(MFA);

        const csv = await fetchReport(
            page,
            `/assessments/${uuid}/report/defences.csv`,
        );
        expect(csv.status()).toBe(200);
        expect(await csv.text()).toContain(MFA);
        // The CSV is the whole report now (item 25), not the ranked defence
        // list it was — so a risk title has to be in it too.
        expect(await csv.text()).toContain('Payment platform');

        const json = await fetchReport(
            page,
            `/assessments/${uuid}/report/export.json`,
        );
        expect(json.status()).toBe(200);
        expect(await json.text()).toContain('Payment platform');

        // Both OOXML formats are ZIP containers. An HTML file renamed .docx
        // is not, and it is precisely the fake D34 refused to ship — it opens
        // with a repair dialog, which is worse than not offering the format.
        for (const path of ['export.xlsx', 'export.docx']) {
            const response = await fetchReport(
                page,
                `/assessments/${uuid}/report/${path}`,
            );
            expect(response.status()).toBe(200);

            const bytes = await response.body();
            expect(bytes.subarray(0, 2).toString('latin1')).toBe('PK');
        }
    });

    /**
     * D39, in a real browser with a real anonymous session — every other
     * test in this file logs in as `adminEmail` first, which exercises only
     * the authenticated branch. This one mints a share token the same way
     * `tests/e2e/13-share-link.spec.ts` does (that file owns the general
     * share-link security assertions; this is the one report-specific
     * consequence of D39: the guest branch renders no sidebar and no
     * `AssessmentActions`, but the report content, search, options and zoom
     * are unchanged).
     */
    test('an anonymous share-link guest sees the report with no sidebar and no AssessmentActions', async ({
        browser,
    }) => {
        const ownerContext = await browser.newContext();
        let shareUrl: string;
        let uuid: string;

        try {
            const ownerPage = await ownerContext.newPage();
            await login(ownerPage, adminEmail, adminPassword());
            uuid = await openAssessment(ownerPage);

            const created = await apiRequest(
                ownerPage,
                'post',
                `/assessments/${uuid}/share/token`,
            );
            expect(created.status).toBeLessThan(300);
            shareUrl = (created.body as { share_link: string }).share_link;
        } finally {
            await ownerContext.close();
        }

        const guestContext = await browser.newContext();
        try {
            const guestPage = await guestContext.newPage();
            await guestPage.goto(shareUrl);
            await guestPage.goto(`/assessments/${uuid}/report`);

            await expect(
                guestPage.getByRole('heading', {
                    name: 'Executive summary',
                    exact: true,
                }),
            ).toBeVisible();

            // D39: no sidebar landmark for a guest.
            await expect(
                guestPage.getByRole('navigation', { name: 'Main' }),
            ).toHaveCount(0);

            // D40: no AssessmentActions — History sits behind `auth`
            // middleware and would be a broken link for this session.
            await expect(
                guestPage.getByRole('button', { name: 'History' }),
            ).toHaveCount(0);
            await expect(
                guestPage.getByRole('button', { name: 'Settings' }),
            ).toHaveCount(0);

            // Export and Print stay reachable for the guest — neither route
            // carries `auth` middleware.
            await expect(
                guestPage.getByRole('button', { name: 'Export' }),
            ).toBeVisible();
            // W83's review fix round aligned this button's accessible name
            // with the authenticated chrome's own `aria-label="Print
            // report"` (Show.vue) — one action does not get two accessible
            // names depending on which chrome renders it. This assertion
            // used to read the pre-W83 exact name 'Print'.
            await expect(
                guestPage.getByRole('button', { name: 'Print report' }),
            ).toBeVisible();

            // Search and the options row are the same for a guest as for an
            // authenticated viewer (W83's whole point: the report content,
            // search, options and zoom do not depend on which chrome it is
            // in).
            await expect(
                guestPage.getByRole('searchbox', {
                    name: 'Search the assessment',
                }),
            ).toBeVisible();
        } finally {
            await guestContext.close();
        }
    });
});
