/**
 * Ported from the legacy `config/nodes.php` (deleted in W2; recovered from
 * git history: `git show master:config/nodes.php`). That file was the one
 * place the old app wrote down "what a node type is allowed to do" — which
 * icon, which label, what it may contain. CLAUDE.md calls this "distilled
 * product knowledge" and requires it survive the rewrite as a typed
 * constant rather than be re-invented ad hoc in components.
 *
 * Mapping notes from the legacy config:
 *   Tree     -> root      (icon-treediagram    -> target)
 *   Risk     -> risk      (icon-exclamation-sign -> triangle-alert)
 *   Attack   -> attack    (icon-sword          -> swords)
 *   Defence  -> defence   (icon-securityalt-shieldalt -> shield)
 *   Transfer -> transfer  (icon-arrow-right    -> arrow-left-right)
 *
 * `transfer` is NOT a database type (schema.md, CLAUDE.md "Domain model in
 * one screen"): it is a defence row with `is_transfer = 1`. It keeps its
 * own entry here — same as in the legacy config, which also listed it
 * separately with `type: 'defence'` — purely so the UI has a label, icon
 * and colour token to render the presentation variant with.
 */
import type { IconName } from '@/Components/UI/icons.generated';

export type NodeType = 'root' | 'risk' | 'attack' | 'defence';

/** The type union actually rendered on a node card: DB types plus the transfer variant. */
export type CardType = NodeType | 'transfer';

/** Matches the `node-{type}-{bg,border,text}` tokens defined in resources/css/app.css. */
export type NodeColorToken = CardType;

export interface NodeTypeMeta {
    /** Singular display label, e.g. "Risk". */
    label: string;
    /** The type a new child of this node is created as, or null if this type is a leaf. */
    childType: NodeType | null;
    icon: IconName;
    color: NodeColorToken;
}

export const NODE_TYPES = {
    root: {
        label: 'Target of Assessment',
        childType: 'risk',
        icon: 'target',
        color: 'root',
    },
    risk: {
        label: 'Risk',
        childType: 'attack',
        icon: 'triangle-alert',
        color: 'risk',
    },
    attack: {
        label: 'Attack',
        childType: 'defence',
        icon: 'swords',
        color: 'attack',
    },
    defence: {
        label: 'Defence',
        childType: null,
        icon: 'shield',
        color: 'defence',
    },
    transfer: {
        label: 'Transfer',
        childType: null,
        icon: 'arrow-left-right',
        color: 'transfer',
    },
} as const satisfies Record<CardType, NodeTypeMeta>;

/**
 * Types whose card renders an "add child" action. Mirrors the legacy
 * semantics where only types with a `create` entry in config/nodes.php got
 * the button — defence and transfer are leaves (docs/specs/ui.md §3.2).
 */
export function hasChildType(type: CardType): boolean {
    return NODE_TYPES[type].childType !== null;
}
