/**
 * Shapes returned by CommentController (app/Http/Controllers/
 * CommentController.php's commentToArray() allowlist). `author`/
 * `resolved_by` deliberately expose only `id`/`name` — never email — per
 * the rule that comment authors expose id and name only.
 */
export interface CommentAuthor {
    id: number;
    name: string;
}

export interface Comment {
    id: number;
    body: string;
    author: CommentAuthor | null;
    created_at: string | null;
    updated_at: string | null;
    resolved_at: string | null;
    resolved_by: CommentAuthor | null;
    can_edit: boolean;
    can_delete: boolean;
    replies: Comment[];
}
