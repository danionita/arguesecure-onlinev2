import type { Page } from '@playwright/test';

/**
 * Direct HTTP against the application, issued from inside the browser so it
 * carries the real session cookie and the real CSRF token.
 *
 * Two different scenarios need this, for opposite reasons.
 *
 * **Sharing has a UI now (W27), but scenarios 3 and 11 still drive its
 * endpoints directly.** `Components/Share/ShareDialog.vue` is mounted from
 * `Pages/Assessment/Show.vue` behind a "Share" button, but exercising the
 * dialog itself is a UI test, not what these two scenarios are about — 3 is
 * about what a shared collaborator can then do, and 11 is about what an
 * anonymous token holder cannot. Calling `assessments/{assessment:uuid}/
 * share/**` straight from the page's own session, the way support/tree.ts's
 * `openAssessment()` already returns the uuid these URLs need, keeps both
 * scenarios focused on their actual assertion instead of re-testing the
 * dialog's own form controls.
 *
 * **Scenario 11 must forge a mutation on purpose.** "The API rejects a
 * hand-crafted mutation" is not something a page can be made to do; the
 * point is that the browser's own credentials plus a valid CSRF token are
 * still not enough, because authorization is server-side. So the same
 * mechanism is used to attack the app as to set it up.
 *
 * `window.axios` is Laravel's own configured instance (resources/js/
 * bootstrap.ts) — it sends the XSRF-TOKEN cookie as X-XSRF-TOKEN and
 * credentials with every request, exactly as the application's own code
 * does. Using it means these calls are indistinguishable from the app's,
 * which is what makes a rejection meaningful.
 */
export interface ApiResult {
    status: number;
    body: unknown;
}

/** Issues a request from the page's own session and never throws on 4xx/5xx. */
export async function apiRequest(
    page: Page,
    method: 'get' | 'post' | 'patch' | 'delete',
    url: string,
    data?: unknown,
): Promise<ApiResult> {
    return page.evaluate(
        async ({ method, url, data }) => {
            const axios = (window as unknown as { axios: any }).axios;
            try {
                const response = await axios.request({ method, url, data });
                return { status: response.status, body: response.data };
            } catch (error: any) {
                // A rejected request is the expected outcome half the time
                // here, so it is a value, not an exception.
                return {
                    status: error?.response?.status ?? 0,
                    body:
                        error?.response?.data ??
                        String(error?.message ?? error),
                };
            }
        },
        { method, url, data },
    );
}
