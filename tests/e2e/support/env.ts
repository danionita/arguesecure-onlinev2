/**
 * Credentials for the end-to-end run come from the environment, never from
 * a file. CLAUDE.md is absolute about this — "never write a secret, key,
 * password, or credential into any file, including seeders, factories,
 * tests, fixtures, and documentation" — and deleting the legacy app's
 * documented default admin password is one of the reasons this rewrite
 * exists. A fixture password committed here would put it straight back.
 *
 * Generate one per run and export it before starting, e.g.
 *
 *   export E2E_ADMIN_PASSWORD="$(openssl rand -base64 24)"
 *
 * The first scenario creates the admin with it through the real first-run
 * setup screen; every later scenario logs in with it.
 */
function required(name: string): string {
    const value = process.env[name];

    if (value === undefined || value === '') {
        throw new Error(
            `${name} is not set. The e2e run needs a password for the admin it creates, ` +
                'and this project never commits one. Generate a throwaway value and export ' +
                `it, e.g. export ${name}="$(openssl rand -base64 24)".`,
        );
    }

    return value;
}

export const adminEmail =
    process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.test';
export const adminName = 'E2E Admin';

export function adminPassword(): string {
    return required('E2E_ADMIN_PASSWORD');
}

/** A second account, created by the admin, for the sharing scenarios. */
export const collaboratorEmail =
    process.env.E2E_COLLABORATOR_EMAIL ?? 'e2e-editor@example.test';
export const collaboratorName = 'E2E Editor';

export function collaboratorPassword(): string {
    return required('E2E_COLLABORATOR_PASSWORD');
}
