/**
 * Shapes returned by the W18 revision routes (routes/web.php's "W18 —
 * comments + history" block). `changes`' shape depends on `action`
 * (schema.md §3.9): 'create'/'update' are field-keyed
 * `{ [field]: { from, to } }`; 'link'/'unlink'/'link_update' use
 * `{ other_uuid, other_title, pivot }`; 'move' and 'delete'/'restore' have
 * their own shapes. Only 'create'/'update' can be reverted by field — see
 * RevisionEntry.vue.
 */
export interface RevisionUser {
    id: number;
    name: string;
}

export type RevisionAction =
    | 'create'
    | 'update'
    | 'delete'
    | 'restore'
    | 'move'
    | 'link'
    | 'unlink'
    | 'link_update';

export interface FieldChange {
    from: unknown;
    to: unknown;
}

export interface NodeRevision {
    id: number;
    action: RevisionAction;
    changes: Record<string, unknown>;
    operation_id: number | null;
    user: RevisionUser | null;
    created_at: string | null;
}
