export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
}

/**
 * The `reverb` shared prop (HandleInertiaRequests::reverb()). Present on
 * every page because the credentials are generated at container start and
 * so cannot be baked into the bundle — see useRealtime.ts.
 */
export interface ReverbProps {
    key: string;
    host: string | null;
    port: number | string | null;
    scheme: string | null;
}

/** The `nav.version` shared prop (ReleaseInfo::for()), W124. */
export interface VersionProps {
    label: string;
    notesUrl: string;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
    };
    reverb: ReverbProps;
};
