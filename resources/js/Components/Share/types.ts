/**
 * Mirrors ShareController::payload() (app/Http/Controllers/ShareController.php).
 * Kept as a single hand-written source of truth here rather than generated,
 * matching the rest of the W10 component library's approach to prop types.
 */
export interface ShareUserSummary {
    id: number;
    name: string;
    email: string;
}

export interface ShareCollaborator {
    id: number;
    user: ShareUserSummary;
    role: ShareRole;
    invited_by: { id: number; name: string } | null;
    invited_at: string | null;
    accepted_at: string | null;
}

export type ShareRole = 'viewer' | 'commenter' | 'editor' | 'manager';

export const SHARE_ROLES: readonly ShareRole[] = [
    'viewer',
    'commenter',
    'editor',
    'manager',
];

export const SHARE_ROLE_LABELS: Record<ShareRole, string> = {
    viewer: 'Viewer — can view',
    commenter: 'Commenter — can view and comment',
    editor: 'Editor — can view, comment and edit',
    manager: 'Manager — can also share and delete',
};

export interface SharePayload {
    owner: ShareUserSummary;
    collaborators: ShareCollaborator[];
    is_public: boolean;
    share_link: string | null;
    share_token_created_at: string | null;
}
