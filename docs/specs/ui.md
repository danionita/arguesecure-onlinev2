# UI Specification

How the ArgueSecure interface is built and behaves: design system, navigation, the assessment editor (list and chart views), keyboard map, canvas controls, real-time collaboration and the node modal. Sections 8 onward cover the dashboard, reports, admin and accessibility.

Related: numbers come from [calculation-engine.md](calculation-engine.md), entities from [schema.md](schema.md), the security rules from [security.md](security.md).

---

## 1. Principles

Editing a risk model in a workshop has to keep up with the conversation, so the interface is organised around removing steps from the edit loop.

1. **Never navigate to edit.** Editing happens inline or in a modal over the tree. The tree never unmounts.
2. **The keyboard is the primary interface.** A facilitator typing while people talk cannot reach for a mouse. Every frequent action has a key.
3. **Density over decoration.** The aim is to show as many nodes at once as the screen can carry legibly.
4. **The numbers are always on screen.** Quantification is the headline feature; it is not hidden behind a modal.
5. **State what is unknown.** A missing value is shown as missing, never as zero and never as a blank that reads as zero.

---

## 2. Design system

### Tokens

Tailwind 4 theme tokens, defined once in `resources/css/app.css`:

- **Node type colours** — root, risk, attack, defence, transfer. Each has a background, border and text variant (`node-{type}-{bg,border,text}`).
- **Status colours** — implemented (settled/green family), todo (planned/amber family), shelved (muted/grey family), each with background, border and text.
- **Magnitude ramp** — five sequential steps (`magnitude-1` … `magnitude-5`, background and text) for "how much does this contribute". Sequential, not diverging; the quantity has no meaningful midpoint.
- **Surface, border, text** tokens (`surface-0/1/2/overlay`, `border-subtle/default/strong`, `text-primary/secondary/muted/inverted/link`) for light and dark mode.

Light and dark are both supported through `prefers-color-scheme` plus an explicit toggle (see Theme below).

**Colour is never the only channel.** Status also carries an icon; magnitude also carries a number.

### Node type registry

`resources/js/constants/nodeTypes.ts` is the single typed source of what a node type is called, which icon it uses and what a new child of it is:

```ts
export const NODE_TYPES = {
  root:     { label: 'Target of Assessment', childType: 'risk',    icon: 'target',           color: 'root' },
  risk:     { label: 'Risk',                 childType: 'attack',  icon: 'triangle-alert',   color: 'risk' },
  attack:   { label: 'Attack',               childType: 'defence', icon: 'swords',           color: 'attack' },
  defence:  { label: 'Defence',              childType: null,      icon: 'shield',           color: 'defence' },
  transfer: { label: 'Transfer',             childType: null,      icon: 'arrow-left-right', color: 'transfer' },
} as const
```

`transfer` is a presentation variant of a `defence` with `is_transfer = 1`. It is **not** a database type.

### Components

`resources/js/Components/UI/` holds the shared primitives: Button, IconButton, Chip, Badge, StatusPill, Modal, Dropdown, Tooltip, Toast and ToastContainer, Tabs, Input, Select, Combobox, Toggle, SegmentedControl, Slider, Avatar, AvatarStack, Skeleton, EmptyState, ConfirmDialog, UnsavedChangesDialog, ReparentDialog, Bar (proportional fill), Money, Percent and Icon.

Icons are a single self-hosted set (Lucide, compiled into `icons.generated.ts`). There is no icon font, no CDN and no external request — see [security.md §6](security.md#6-content-security-policy).

### Navigation — a left sidebar

The application chrome is a left sidebar (`Components/Nav/Sidebar.vue`), rendered by `Layouts/AuthenticatedLayout.vue`. There is no top bar. The sidebar is application-scoped; anything that acts on one assessment lives in that assessment's action bar (see Page chrome below).

```
┌────────────────────┐
│ ▣ ArgueSecure  vX  │  logo -> dashboard; running version links to its release notes
│ ‹ Collapse         │  the width toggle, in its own row at both widths
├────────────────────┤
│ ⌂ Dashboard        │
│ ▾ My assessments   │  disclosure; the label links to the full list
│   ↳ Payment plat…  │
│   + New assessment │  opens the creation dialog
│ ▸ Shared with me   │  label -> /assessments/shared; each row names the owner
│ ⧉ Templates        │
├────────────────────┤  (bottom)
│ ? Help             │  -> help.index
│ ⚑ Notifications  ③ │  opens the feed panel
│ ◐ Theme            │  light / dark / system
│ ⚙ Admin            │  only when the user is an admin
│ ◔ Profile          │
│ ⏻ Log out          │
└────────────────────┘
```

The sidebar's data (the user's assessments, those shared with them, the unread count, the version) arrives as the shared Inertia prop `nav`.

**Help** is reachable from the sidebar and reads as current on `/help` and every `/help/{slug}`. The help pages are `resources/markdown/help/*.md`, listed by `App\Domain\Help\HelpLibrary`, and are also the assistant's help corpus (docs/ASSISTANT.md). `Components/Help/HelpShell.vue` chooses the chrome on `auth.user`: a signed-in reader gets the sidebar; a guest (help sits outside `auth`, for workshop guests and share-link readers) gets a standalone header with a **Log in** link, because `AuthenticatedLayout` has no user to render for them.

**The rail shows a visible tooltip, not only an accessible name.** A compact `NavItem` moves its label to `aria-label` and the native `title`, and wraps itself in the shared `Tooltip` component, so hovering or focusing a rail icon shows the label on screen. The expanded panel shows the label as text and does not wrap.

**Two widths, from one persisted preference plus one contextual override** (`Components/Nav/useSidebar.ts`). The panel is docked open at 240px (`w-60`) by default. Inside an assessment (any `/assessments/{uuid}` URL) it is pinned to a 48px icon rail (`w-12`), and expanding it **floats a panel over the content** with a backdrop, closed by clicking the backdrop or `Escape`. The editor is a canvas: the chart lays out to the width it is given, and a nav that reflowed it would rearrange the picture someone is pointing at. The gutter the layout reserves therefore depends on the rail state alone and never on whether the floating panel is open.

| `railed` | `overlayOpen` | What the user sees |
|---|---|---|
| false | n/a | Docked, 240px gutter, labels visible |
| true | false | 48px rail, icons only |
| true | true | 48px rail plus a floating 240px panel |

The docked/collapsed choice is persisted in `localStorage` under `arguesecure.sidebar`. The in-assessment override never overwrites it, so leaving an assessment restores the user's choice. Navigating into or out of an assessment closes the floating panel.

**The width toggle has one location**: a dedicated full-width row directly below the product mark, at both widths, with fixed row heights so that its position does not change when the width changes. It is labelled by visible text when there is room ("Collapse navigation") and by `aria-label` when there is not ("Open navigation" / "Close navigation"); this is the rule every `NavItem` follows.

**"New assessment" opens the creation dialog.** It links to `assessments.index` with `?new=1`, which opens the dialog owned by `Pages/Assessment/Index.vue` (title, description and a Scale select). It sits inside the My assessments group, below the list it adds to. In the rail the group collapses to a bare link and has no list to sit under, so the create control is rendered at the top level instead; the two renderings are mutually exclusive. Creation has a single flow and a single write path, `assessments.store`. The dialog's **Scale** select lists the admin-defined scales, Standard first and preselected, and is sent as `scale_set_key`; an unknown key is a 422 (`StoreAssessmentRequest`), never a silent fallback to Standard. It is not drawn when the server sent no scales.

**Accessibility:**

- A `<nav aria-label="Main">` landmark, with a skip-to-content link before it in DOM order targeting a focusable `<main id="main-content" tabindex="-1">`.
- The width toggle is a real `<button>` carrying `aria-expanded`.
- Groups are disclosures: a `<button>` with `aria-expanded` and `aria-controls` pointing at the list it controls.
- In the rail, labels move to `aria-label`; they never disappear. A notification count that cannot be drawn goes into the accessible name ("Notifications, 3 unread") rather than a silent dot.
- Destinations come first and account controls last in DOM order, so tabbing walks the sidebar as it reads.
- Nothing in the sidebar is inside a menu that could be a link.

**Notifications opens a panel, not a page.** It is the one sidebar item with no `href`: it renders a `<button>` through the same `NavItem`, so its styling, accessible name and rail dot match the destinations around it. The feed (`Components/Nav/NotificationPanel.vue`) carries three kinds in one list: **you were mentioned in a comment**, **a node was assigned to you**, and **an executive summary you requested has finished (or failed)**. Mention and assignment rows link to the node through the node modal's `?node=` deep link; a summary row links to the report. A row whose node has since been deleted renders as text. **Opened is read**: the badge counts unread only, and opening the panel marks everything read (`PATCH /notifications`); the rows stay visible. The unread count is a shared Inertia prop; the feed itself (`GET /notifications`) is fetched when the panel opens, not on mount. Notifications are in-app only; no email is sent.

**Hiding the Admin item is courtesy, not authorization.** `EnsureUserIsAdmin` returns 403 on the route regardless of what the client renders.

### Page chrome

`resources/js/Components/Chrome/` holds the geometry above a page's content and is the only place it is defined:

| Component | What it owns |
|---|---|
| `ChromeHeaderBand.vue` | The bordered band around a title row: `border-b`, the page surface, `px-4 py-4 sm:px-6`. |
| `ChromeTitleRow.vue` | The title and the actions beside it, at a stated `min-h-7`. |
| `ChromeToolbarBand.vue` | The toolbar band and the row inside it (both paddings), the right-hand control group (`trailing` slot) and the view switcher's `anchor` slot. |

`AuthenticatedLayout` renders the header band around its own `header` slot. The report renders the same three components directly, because it cannot be wrapped in `AuthenticatedLayout`: a share-link guest has no `{name, email}` user for it to render. The account chrome, the sidebar and the notification feed stay in the layout; nothing in `Components/Chrome/` knows about a user, an assessment or a store.

`min-h-7` on the title row is stated rather than inherited. It is the height of a `size="sm"` Button, so the band's height, and with it the vertical position of the view switcher below, does not change when a page hides one of its actions.

**The assessment action bar** (`Toolbar/AssessmentActions.vue`) holds, in this order: **Settings · History · Share · Export · Print**. It is rendered into a page's `ChromeTitleRow` and takes the assessment, the caller's `abilities` and the current `viewMode` (`chart`, `list` or `report`). It renders nothing a viewer cannot do: Settings and Share require `manage` (and Share is also omitted for a template, which has no owner to share from); History, Export and Print need only the `view` that got the bar mounted. Share stays a page-level dialog: the component emits `share`. **Print** is a menu that asks which view to print (Chart, List or Report, the current view first); choosing the current view calls `window.print()`, another view switches to it (or navigates to it) and then prints. The bar is mounted in `Pages/Assessment/Show.vue` for the chart and list; the report mounts it for authenticated viewers only (§9).

**`ChromeHeaderBand` hides itself from print** (`no-print`), so printing the chart or the list does not print the title row's actions. The editor's own running header (`AuthenticatedLayout`'s `.print-header`, inside `<main>`) carries the assessment title and the print date.

### Theme

Three states, **light / dark / system**, as a `radiogroup` at the bottom of the sidebar, collapsing to a single cycling button in the rail so `system` stays reachable at either width.

`system` is the default and a real choice: it is the only value that follows the operating system. It is expressed by removing both the `light` and `dark` classes from `<html>`, because `app.css` scopes its `prefers-color-scheme` block to `:root:not(.light):not(.dark)`. The preference is stored in the browser's `localStorage` under `arguesecure.theme`; it is not stored on the user's profile.

The theme is applied before the first paint, in two places that agree on the storage key: `useTheme.ts` reads storage during setup (not `onMounted`), and a nonce'd inline script in `resources/views/app.blade.php` covers the gap before any module runs.

---

## 3. The tree editor

Route `/assessments/{uuid}`. This is the screen the tool is built around.

**Two views over one store.** The **chart** (§3.7) is the default: the assessment as an org chart, four tiers, built for a room. The **indented list** (§3.2) is the reading surface. One store, one editor view and one modal serve both; switching never reloads and never loses collapse state, filters, focus or search. The choice is persisted in the browser per user per assessment (`arguesecure:viewmode:{user}:{uuid}`). The report is a third position of the same view switcher (§9).

### 3.1 Rendering a DAG, not a tree

A node may appear in several places: an attack under two risks, a defence under three attacks. The renderer keys on **(node uuid, path)**, not on node uuid alone. The store holds each node once; the view projects it into every position it occupies.

Expansion, focus and selection state belong to the **occurrence**, not the node: collapsing one occurrence of a shared defence does not collapse the others.

### 3.2 Node card

Compact by default: one line when collapsed, three at most when expanded.

**The list column is capped at a reading measure.** The chart is a canvas and takes the whole window; the list does not. The cap is `max-w-4xl` on the `<ul role="tree">`, centred with `mx-auto`. It is a cap, not a width: narrow windows are unaffected, and because it sits on the zoomed element the measure grows with the type when someone zooms in. Print is unaffected: `print.css` resets zoom and fixes the type at 9pt.

**Line 1 — always visible:**
`[expand] [type icon] Title [child count when collapsed] [value chips] [indicator icons] [actions]`

**The list shows what a human entered.** The list is the view for filling in values, so the card carries inputs (impact, likelihood, status, strength) and no engine output. Everything computed appears in the modal's Statistics tab and in the report, against all three baselines at once; the one exception is the root's three totals, below.

Every figure is labelled, and every label, chip and bar explains itself on hover and on focus. The copy lives in one module, `resources/js/constants/valueHints.ts`, because the chart renders the same ideas in a different shape and the two views must not explain one figure differently.

**Value chips** by type:

| Type | Chips |
|---|---|
| root | `Base`, `Current`, `Target ALE` totals, plus a risk-tolerance statement when a tolerance is set |
| risk | `Impact: €X` (currency or level label) |
| attack | `Likelihood: 0.25/yr` (or the level label) |
| defence | status pill, `Reduces likelihood: 40%` |

- **The root carries all three totals**, labelled **Base / Current / Target ALE**. "Base" is used rather than the report's formal term "inherent" because the card is a workshop surface; `ROOT_TOTALS_HINT` is the sentence that tells a reader the two words are one idea. A total that excludes pairs with missing values is shown as a lower bound (`at-least`), and each non-base total carries its reduction against base. The chips are a polite live region, so a collaborator's edit moves them without the reader touching anything. The risk-tolerance statement reports where the current total sits relative to the tolerance configured in assessment settings; it is a comparison, never a red/amber/green band ([calculation-engine.md](calculation-engine.md) forbids band-mapping a computed figure).
- **Every entered value on the row is a `ValueChip`, and only entered values are.** One component, one label-colon-value shape, one hover/focus explanation apiece: impact on a risk, likelihood on an attack, the edge's strength on a defence, and the root's three totals.
- **Strength and reduction target are one chip:** the **reduction target is the label** and the **strength is the value**, so `Reduces likelihood: 40%`. An unset reduction target reads `Reduces overall loss`, which is the documented default and not a missing value; it is never shown as `?`, which on this row means a missing strength.
- **Strength is read from the edge this occurrence sits on**, never from the defence. It lives on the `attack_defence` pivot, so one control shows 80% under one attack and 20% under another, and both are correct.
- **The root carries no completeness bar.** A bar states a fraction of a whole, and the whole (how many risk/attack pairs the assessment has) is not on the row. The figure is reachable in the modal's Statistics tab and the report as a count of pairs. `Bar.vue` serves those surfaces.
- The status pill states implemented / todo / shelved outright. Defence rows are not dimmed by baseline: no baseline is selected anywhere in the editor.

A missing value renders as a **grey `?` chip**, never as `0` and never as blank. The hover text distinguishes the two kinds because they behave differently: a missing impact or likelihood **excludes the pair from the total**, while a missing strength is treated as **no reduction at all**, which overstates residual risk (the safe direction).

**Indicator icons.** A node that appears in more than one place shows a `shuffle` counter. For an attack it is a button that opens the **Appears under** popover (§7); for a shared defence it is a tooltip listing the attacks it sits under. An unresolved-comment count is shown with a message icon and a tooltip. A node locked by another user shows a lock with the holder's name.

**Actions** are small and low-contrast until row hover or focus, then full contrast: **add child**, **add from template** (§3.3), **suggest** (only where the assistant is available, §7) and **delete** (which asks for confirmation). Types with no `childType` (defence, transfer) do not render add child, add from template or suggest. On touch, actions are always full contrast. There is no reveal menu.

The title is opened for editing by **clicking it** (§3.4). There is no pencil or details icon.

### 3.3 Adding a node

Pressing add (the card's **+**, or `Enter` / `Tab` on a focused row) opens the node modal on a **draft**: the type it will be and the parent it will go under, with the title field focused. Type the name and press `Enter` (or **Add**): the node is created once, with its title, and the modal switches to it so its values can be filled in next. `Escape` or Cancel abandons the draft.

- **Nothing is written until the draft is confirmed.** An untitled node is never created, so collaborators are not told "someone added a node" before it has a name, and abandoning a draft leaves nothing to clean up.
- The draft carries a title and description, and for a defence its status and transfer flag. Everything else is set on the node's own tabs once it exists.
- Adding requires `update`.

#### Suggestions: link, don't duplicate

While the draft's title field is being typed into, it offers **attacks or defences that already exist in this assessment** of the type being added. Choosing one **links** it (one node, a second parent) and closes the modal, instead of creating a same-titled twin. Without this, an assessment ends up with the same attack three times under three risks: three nodes, three sets of defences to keep in step, and a total that counts one attack as three problems.

- **The fast path is not slower.** Nothing is highlighted until `ArrowDown`. Typing a new name and pressing `Enter` creates a node in the same keystrokes as without suggestions.
- **It is advisory.** The list never takes focus, moves the caret or rewrites what was typed. `Escape` closes it; a second `Escape` abandons the draft.
- **Only on insert, never on rename.** Renaming an existing node to match another is not a request to merge them.
- **Every row says where the node already appears** ("already under 2 risks").
- **Nodes already under this parent are not offered**, so no choice can write a duplicate edge.

Suggestions come from `GET /assessments/{uuid}/nodes/search`, gated on `update` rather than `view` (it feeds an edit, and a `view` gate would hand every node title out, one keystroke at a time, to anyone holding a share link) and throttled by the `search` limiter. The same list serves the list and the chart. It is offered for attacks and defences; risks have a single parent (the root) and are not suggested.

The assistant's **AI suggestion** and **From template** are offered on the draft too, before anything is typed; see §7's "Three offers on an empty title". Either fills the form; the person still presses Add.

#### The Templates page

Every account sees the list and **Use this template**. An administrator also sees **New template** (an empty, ownerless template that opens in the editor), and **Edit** and **Delete** on each row; Delete confirms and is permanent. Edit opens the ordinary editor with a banner saying it is a template, and without **Share** (a template has no owner to share it from). **Publish as template** lives in an assessment's Share dialog, for admins only, behind an inline confirmation (not a nested modal), and copies the assessment: the copy is not linked back and cannot be unpublished.

#### Adding from a template

Templates are not only for starting an assessment. The same library answers "what could go under this node?" in two places:

- **On the card** — an **Add from template** action (copy icon, labelled *Add attacks from a template* and so on) beside Add child, on both the list and the chart card, gated like Add child on `update` and on the node having a child type. It opens `TemplatePicker.vue` in `multi` mode: first the templates, each with its count of nodes of the child type (a template with none is disabled), then that template's nodes of the child type, filtered as you type on title and description, each with the parents it sits under in the template. One action per item, as many as wanted; the panel stays open until **Done**. `useTemplateChildren.ts` mirrors `useAssistantChildren.ts` for the same rhythm.
- **In the draft form** — **From template** opens the same picker in `single` mode: **Use** on one item fills Title and Description (and a defence's transfer flag), closes the picker, and shows a note naming the template. Nothing is saved; the person edits and presses Add.

What is copied is the node's **words, never its values**: `title`, `description`, `is_transfer`, through the ordinary `addChild` write. A template's impacts, likelihoods and strengths describe its example system, and the standards templates hold placeholders; a copied figure would look like somebody's judgement (`app/Domain/Templates/TemplateNodes.php`).

**Link, don't duplicate applies here too.** In `multi` mode an item whose title (trimmed, case-insensitive) matches an existing node of the child type is offered as **Link**, which links that node under this parent instead of copying it; if it is already under this parent it reads *Already here* and does nothing. Risks cannot be linked (one parent, the root), so an existing risk is always *Already here*. In the draft form the filled title runs the ordinary search, so the same match is offered as a link there.

The picker sits inside the card, so while it is open the card **stops being a drag source** (a press-and-move on its text would otherwise pick the card up), and in the chart a click inside it neither selects the card nor reaches the double-click handler.

Endpoints: `GET /templates/options` (every usable template with node counts per type) and `GET /templates/{uuid}/nodes?type=risk|attack|defence`, both behind `AssessmentPolicy::useAsTemplate` (security.md §3.1a).

### 3.4 Editing a node

**The list and the chart edit a node the same way.** A single click on a node's title or description opens the node modal on **Details**; a click on an entered value (impact, likelihood, strength) opens it on the tab that holds that value; each such area has a pointer cursor. `F2` or `Space` on a focused row opens it too. The list has no inline title field.

The one inline edit is the **description in the list**: double-click it to edit in place (`Enter` commits, `Escape` reverts). A single click there waits out a 250 ms double-click window before opening the modal, so a double-click never opens the modal on top of the field.

**One editor per node.** While the modal is open on a node it holds a whole-node lock (`node`, see [§6](#6-real-time)). Anyone else opening that node gets it read-only with "Currently being edited by …" in the header; cards show a lock with the holder's name; and `NodeController::update` refuses their writes with **423**. When the holder closes the modal, the other person's becomes editable in place. The inline description edit takes the same lock.

### 3.5 Autosave and conflict handling

An explicit indicator in the title row: **Saving… / Saved / Conflict**.

Every node PATCH carries the node's `updated_at`. On mismatch the server returns **409** with the current server state, and the client shows an inline banner ("… changed this while you were editing") with **Keep mine** / **Take theirs** / **Cancel**. The client never silently clobbers and never silently discards the user's typing.

Locks are cooperative UX; the version check is the correctness guarantee. Both exist because a lock can expire mid-edit.

### 3.6 Undo

`Ctrl/Cmd+Z` undoes and `Shift+Ctrl/Cmd+Z` redoes (`POST /assessments/{uuid}/undo` and `…/redo`; any editor may undo, not only the operation's author). There is a single linear per-assessment stack over structural changes only: create, delete, move, link, unlink, clone, catalogue insert. Semantics are in [schema.md §3.10](schema.md#310-assessment_operations-the-undo-stack). After a successful undo or redo the page reloads the graph and totals through Inertia, because the inverse is applied server-side.

If the inverse cannot be applied cleanly the server refuses explicitly; it never applies one partially. The server also exposes `GET /assessments/{uuid}/undo`, which returns the next operation's summary and author for a confirmation prompt; the editor does not call it, so `Ctrl+Z` acts without a prompt.

Field edits are **not** on the undo stack; they are recovered from the node's History tab (§7).

### 3.7 Chart view — the default

The assessment as an **org chart**: Target of Assessment on top, then risks, attacks and defences, one tier per row, siblings side by side, connectors drawn downward. The schema fixes the depth at four, so the vertical axis is bounded. At fit-to-width the chart scrolls **horizontally only**; collapsing a risk is how a wide assessment is made to fit, and a **fit to width** button does the rest. **Zoomed in, it scrolls both ways**, since otherwise the bottom tier would be unreachable. The chart view occupies the **full window width**: it is a canvas, not a document.

**The chart's scroll container is the window's height** (`h-[calc(100vh-13rem)]`), not the chart's. The template picker and the assistant's suggestions float below the card that opened them, inside that container, which clips them; a container only as tall as the chart would cut them off below any card with nothing beneath it. A panel opened below the bottom tier of a taller chart scrolls itself into view.

**A collapsed card shows its hidden children.** One bar on the card's bottom edge, absolutely positioned so it adds no height. At rest a collapsed card shows the bar with its button ground and border, holding the chevron and the number of direct children, so it reads as an always-visible expand button; a bar with no count has no ground until hover. On hover or keyboard focus the number slides left as the add-child, template and suggest actions open out beside it; the number is the expand button, so the chevron is not shown while it is. The bar opens on `focus-visible`, never `focus-within` (a mouse click leaves focus on the clicked button, and `focus-within` would keep the bar open after the pointer left). Theme tokens only.

**Layout is a pure function** — `composables/useTreeLayout.ts`: occurrences in, coordinates out, no DOM and no store. Bottom-up width accumulation: a leaf occupies one card; a parent occupies its children plus gaps or its own card, whichever is greater, and centres over that span; a collapsed occurrence is a leaf. Defaults (`TREE_LAYOUT_DEFAULTS`): base card width 176, card height 124, horizontal gap 16, vertical gap 44.

**Card width is per tier**: the base unit times a factor of defence 1, attack 1.25, risk 1.5, root 2. The tiers do not carry comparable amounts of text (the root holds the assessment's title and the whole-graph bar; a defence holds a control's name). Widening the upper tiers widens `layout.width` (which **fit to width** divides the viewport by) **only where a tier's own card is wider than its children's span**: on a wide assessment the leaves set the width, and on a shallow one (a root over a single risk, or an empty assessment) the widest single card sets it. Height is not affected.

**The `ul`/`li` tree is preserved.** Only the card inside each `<li>` is absolutely positioned. This keeps the screen-reader reading of the assessment, `aria-level`, and the keyboard outline, which key on the occurrence path.

**A shared node is drawn once per occurrence**, not once with several incoming edges, which keeps the chart a tiered tree rather than a general DAG-layout problem. Duplicate highlighting (§5) makes the repeats legible.

**What a card shows:** type, title (two clamped lines), the description (three clamped lines), and, in the bottom row, **the node's own value**. Clicking it opens the node modal on the tab that fixes it: Values for a risk or an attack, Strength for a defence (strength lives on the `attack_defence` edge and so is per occurrence). Defences also carry a status **icon only** (implemented / todo / shelved), with the word in the tooltip and the accessible name, and the value sits after that glyph. The card also carries the "appears in N places" counter, a lock when held by another user, and an unresolved-comment dot.

| Type | Set | Unset |
|---|---|---|
| Risk | `€400k` (impact, abbreviated) | `?`, in the missing-value styling |
| Attack | `0.4/yr` (rate per year) | `?` |
| Defence | status glyph, then `60%` (strength **on this edge**) | glyph, then `?` |
| ToS | a two-segment bar: current and target loss as a fraction of inherent | nothing; the root has no own values |

- **The defence figure is per occurrence.** The same defence under two attacks shows two different percentages. This is why the card takes the edge as a prop rather than looking one up from the uuid.
- **A qualitative value is not a number.** Such a node carries a `*_level_id` and no amount; the level's short code is rendered. `ScaleMapper` is the only thing that turns a level into a figure, and it does so for the engine, against the assessment's own calibration, never for a card.
- **The space is reserved in both states**, so a card does not reflow when a value is filled in. The indicator is always present, and the control that navigates to the values is on screen whether or not values are missing.
- **The root has no indicator**: it owns no values and has no values tab.

The **ToS bar is the one computed figure on the board**, and the ToS is the one node it belongs to: it is the whole assessment's answer rather than any one person's input. Two segments (current and target) against a full-width inherent ground, with the three totals in the tooltip. It is a bar, not a number, so that it is not mistaken for one of the typed-in figures above it, and it has no `?` state because there is nothing to fill in.

**No cues, and no computed figures on the other tiers.** A card shows no annual loss, contribution, completeness or residual; those are answers the list, the modal's Statistics tab and the report give. What a card shows is the value the node was *given*, not a value derived from it.

**No tier gutter.** The tier is announced by every card's accessible name, which begins with its type (*"Risk: Customer data breach"*), and by `aria-level`.

**The card height is a budget.** Two title lines plus three description lines at 1080p fits four tiers: a ≈937px viewport minus ≈193px of page chrome leaves ≈744px, and four tiers at 124px plus three 44px gaps is 628px. The arithmetic is in `useTreeLayout.ts` and pinned by a test. If the toolbar grows a row, the toolbar is what has to give, not the description clamp.

**Interactions:** a click on the title or the description opens the node modal on Details, and a click on the value indicator opens the tab that fixes it (the same gestures as the list, §3.4); a click anywhere else on the card selects it (in a chart, clicking a card is how you point at it while talking); `Enter` on the title opens the modal. A bar at the card's **bottom edge** carries collapse and add child, **add from template** (§3.3) and, where the assistant is available, **suggest**; their panels float below the card rather than pushing the layout. There is no Open or Delete action there: **Delete is in the modal header, on every tab**, because a destructive action reachable only by hovering a two-pixel icon is the wrong thing in a view used on a projector. Adding a child opens the modal on a draft, exactly as in the list (§3.3). The draft's Title field is where the link-don't-duplicate suggestions appear, which is the keyboard route from a single-parent attack to a second link.

**Re-parenting by drag.** Cards are draggable in the chart as well as the list. Dropping a node on a different parent asks **Move or Link** (`ReparentDialog`), because the gesture is ambiguous and the two readings are different mutations, one of which destroys an edge:

| Choice | Effect |
|---|---|
| **Move it here** | The source edge is deleted and the target edge created. One node, one parent. |
| **Link it here as well** | The target edge is created and the source kept. One node, two parents; the counter then says "appears in 2 places". |

Both go on the undo stack, with a summary naming the verb and the destination (*"Linked 'Phishing' to 'Fraud'"*), so that undoing the wrong one is distinguishable.

Three guards:

- **Link is withheld when the edge already exists** (a second identical edge would violate the unique index). The dialog says why the option is missing rather than omitting it silently.
- **Cross-type drags are refused at `dragover`**, not at drop, so the cursor never accepts a drop that then does nothing.
- **Position is appended, never inherited.** Ordering lives on the edge, so a node arriving under a new parent has no position there.

In the chart the drop target is the **parent card itself** (there is no sibling to aim at across tiers). In the list it is a sibling, which also fixes the order. **Reordering within a parent is list-only.**

---

## 4. Keyboard map

The shortcuts are wired by `composables/useKeyboardOutline.ts`, mounted once from `Pages/Assessment/Show.vue`, and documented on the `?` overlay (`Components/Shortcuts/ShortcutsOverlay.vue`).

| Key | Action |
|---|---|
| `↑` `↓` | Move focus between visible nodes |
| `←` `→` | Collapse / expand, or move to parent / first child |
| `Enter` | Add a sibling below (opens a draft under the focused node's parent) |
| `Tab` | Add a child (opens a draft under the focused node) |
| `F2` | Open the node modal on the focused node |
| `Space` | Open the node modal |
| `Escape` | Clear tree focus; also closes the modal, a suggestion list or an inline edit |
| `Delete` / `Backspace` | Delete the focused node |
| `Ctrl/Cmd+Z` / `Shift+Ctrl/Cmd+Z` | Undo / redo |
| `/` | Focus search |
| `Ctrl/Cmd + +` `−` `0` | Zoom in / out / reset |
| `?` | Keyboard help overlay |

- `Enter`, `Tab`, `F2`, `Space` and `Delete` act on the focused tree row. They do nothing while focus is in an input or inside a dialog, and `Tab` is overloaded away from browser focus traversal **only while a tree node has focus**; native `Tab` applies everywhere else, including inside the modal. `Shift+Tab` is not bound.
- `Delete` / `Backspace` on a focused row deletes the node directly; the card's own delete action (and the modal header's) asks for confirmation.
- `Enter` on the root does nothing (it has no siblings); `Tab` on a defence does nothing (it has no child type). Add requires `update`.
- There is no command palette: `Ctrl/Cmd+K` is reserved by the handler and not wired to anything.
- `Ctrl/Cmd+Z` and the zoom keys fire regardless of focus location; `/` and `?` fire unless focus is in an input.

---

## 5. Canvas, search and focus

- **Zoom** — buttons, `Ctrl+scroll` and `Ctrl +/−/0`. Range 50%–200% in 10% steps (`composables/useCanvas.ts`), persisted in the browser **per view, per assessment, per user** (`arguesecure:canvas-zoom:{view}:{user}:{uuid}`), so setting the chart to 70% does not change the list. A value stored under the older key without a view component seeds a view that has no stored value of its own. The two views spend the number differently: the chart scales geometrically as a CSS transform, and the list uses CSS `zoom`, which enlarges the type and leaves the container's width alone so rows reflow inside it (a `font-size` on the list would not move any card, because every card size is a Tailwind `rem` utility resolved against the root element). The control labels itself for the view it is in, "text size" or "chart size". The report has its own zoom with the same range.
  **100% is not a scale factor of 1.** An exported baseline, `CANVAS_BASE_SCALE = { chart: 1.1, list: 1.35, report: 1.0 }`, multiplies into the transform and the `zoom`, so 100% paints what a raw 110% (chart) or 135% (list) would. The stored number, its key, the range, the step and the label are not affected by it. Anything converting layout units to painted pixels (fit to width, and the sizer the chart's scroll container reads) multiplies by the matching view's baseline too. The list's baseline is higher because it is the reading surface and opens more zoomed in, while the chart opens showing the whole shape of the assessment.
- **Row spacing and indent.** List rows sit `mb-1.5` (6px) apart, and each nesting level indents by `ml-4` / `pl-4` (16px). Zoom scales gaps together with text, so it cannot substitute for these.
- **Centring.** The list always centres in its capped viewport (`mx-auto` on the `max-w-4xl` column). The chart centres **only when it is narrower than its scroll container** and left-aligns once it is wider: `mx-auto` on a block with an explicit painted width does exactly that (the auto margin computes to 0 when the content cannot fit), so a wide chart never pushes its root off-screen.
- **Scrolling on both axes**, with panning by middle-drag or space-drag.
- **Search** (`/`) — case-insensitive substring match over title and description, computed in the browser over the nodes already loaded. Every *occurrence* of a matching node counts as a match; the active match is marked with a ring and the canvas scrolls to it; `Enter` and `Shift+Enter` step forward and backward through the matches, wrapping.
- **Filters** — by node type, defence status, tag and **Missing values**. Non-matching nodes dim rather than disappear, so structure stays legible. The *available* filters sit behind one **Filters** trigger carrying a count of what is on, each set under a category heading that names what it filters: **Node type**, **Defence status**, **Tags** (only when the assessment has tags) and **Values**. The trigger and panel are a shell (`FilterPopover.vue`) that holds no filter logic. The *active* filters stay on the bar as removable chips with a one-click clear, so what is filtered is readable without opening anything and the bar does not wrap. For a defence, "missing" means the **strength on that occurrence's edge**: a control rated against one attack and not another is missing a value in exactly one of the two places it appears.
- **Focus breadcrumb** — when a focused occurrence is set in the editor view (`useFocusMode`), everything outside its subtree is hidden and a sticky ancestor-path bar (`FocusBreadcrumb`) shows the way back, with an **Exit focus mode** button and a click on any ancestor to refocus there. No control in the current interface enters focus mode, and `Escape` does not exit it.
- **Collapse/expand** per occurrence. State is persisted in the browser per user per assessment (`useCollapseState`). There is no expand-all or collapse-all control.
- **Duplicate highlighting** — hovering a node that occupies more than one position outlines **every** occurrence of it. The "appears in N places" counter (§3.2, §7) is the persistent, accessible form of the same information: its tooltip lists the parent titles (the risks an attack sits under, the attacks a defence sits under), one per line, capped at eight with "and N more", and the same names go on its accessible name. No connector line is drawn between occurrences.
- **View switcher** — chart, list or report, in the toolbar, persisted per user per assessment for chart/list, defaulting to the chart.
- **Baselines** — no control selects a baseline, because no card in either view shows a baseline-dependent figure. The baseline vocabulary (inherent / current / target) appears where the comparison belongs: the node modal's Statistics tab and the report, both of which show all three side by side. The three totals are on the root card (§3.2); there is no separate totals panel.
- **Toolbar layout** — one row, in order: search · filters · (spacer) · zoom · view switcher. It must not wrap at 1280px. Toolbar height is height the chart view does not have for its card text (§3.7). The search box, view switcher and zoom controls do not print.

---

## 6. Real-time

Laravel Reverb over per-assessment presence channels; the client is `composables/useRealtime.ts`.

- **Presence avatars** for the other people in the assessment are shown in the editor's title row (`PresenceAvatars`, at most five, then a count). Each client also whispers which occurrence it has focused, and `usePresence` tracks it per path; no card currently renders a per-collaborator ring from it.
- **Locked fields** show an inline *"Currently being edited by …"* indication and become read-only. A lock is never a modal overlay: it must not block reading. Locks are Redis TTL locks refreshed by a 15-second heartbeat over authenticated HTTP (`LockController`), not by a websocket whisper.
- **Granular broadcasts** carry the changed data (node, link and totals events), and the client patches its store directly (`applyRealtimeUpdatesToStore`). It does not fetch a rendered fragment per change.
- **Toasts are throttled and grouped per collaborator**: a burst of changes by one person inside a 1.2-second quiet window becomes one toast, "Dana made 6 changes", and a single change is named ("Dana …"). The actor's own changes never toast.

Websocket authorization is in [security.md §8](security.md).

---

## 7. Node modal

Opened by clicking a node's title, description or entered value, by `Space` or `F2` on a focused row, or by a `?node=` deep link.

### Tabs, by type

| Type | Tabs |
|---|---|
| root | Details · **Scales** · Statistics · References · Assignees · Comments · History |
| risk | Details · **Impact** · Statistics · References · Assignees · Comments · History |
| attack | Details · **Likelihood** · Statistics · References · Assignees · Comments · History |
| defence | Details · **Cost** · **Strength** · Statistics · References · Assignees · Comments · History |

**Scales renders only for someone who may `manage`.** That is the ability `UpdateAssessmentSettingsRequest` is gated on; hiding the tab from a collaborator who may edit nodes but not own the assessment is courtesy, and the FormRequest refuses on its own.

| Tab | Contents |
|---|---|
| **Details** | Title, description and **labels**. For a defence, also **status** and the **transfer flag**. |
| **Scales** | The assessment's own settings, on the node that *is* the assessment; see below. |
| **Impact / Likelihood / Cost** | The one thing this type is quantified by; see below. Only the label differs per type. |
| **Strength** | Per-edge strength, reduction target and rationale for the attacks this defence applies to. Edited from either end of the `attack_defence` edge: one row, one editor. Only the defence has a Strength tab. |
| **Statistics** | Read-only. Every figure the engine computed for this node, across all three baselines side by side. See below. |
| **References** | CVE and similar references. |
| **Assignees** | Who is responsible. Multi-select over the assessment's collaborators. Assigning grants nothing: it puts the node on their dashboard with what it still needs. Someone who is not on the assessment cannot be picked; the tab says so and points at the Share dialog. |
| **Comments** | Threaded, resolvable. |
| **History** | Revision list with per-field revert. |

The Impact, Likelihood, Cost and Strength tabs show a `?` badge when a value is missing.

**Tab keys are not tab labels.** The URL carries `?node=…&tab=…`, and the keys are `details`, `values`, `links`, `statistics`, `references`, `assignees`, `comments`, `history`, `scales`. `values` is the Impact / Likelihood / Cost tab and `links` is Strength. The keys are addresses that appear in shared links and are independent of the visible labels. A deep link naming a tab the node's type does not have (including `?tab=scales` for someone who may not manage the assessment) falls back to Details rather than rendering an empty modal.

### Labels

A chip row on Details with a type-ahead over the labels this assessment already uses. **Typing a name that does not exist creates it**; there is no separate screen for managing the vocabulary.

- **Duplicates resolve by slug, on the server.** "PCI DSS" and "pci dss" are one label; the first spelling wins the display name and is what the response carries, so two people never see one label spelled two ways. The client does not repeat the rule locally.
- **Assessment-scoped**, per [schema.md §3.11](schema.md#311-tags-and-node_tag). The same name in two assessments is two rows.
- **Chips render in the modal only**, not on the chart or list cards, which show only inputs. The exception is the **root node**, whose labels are the assessment's own and appear on its row in the assessment list (§8).
- **Removing a label leaves it in the vocabulary**, so a mistaken removal costs one keystroke rather than a retype into a near-duplicate.
- **No colour on the chip.** `tags.color` exists in the schema and is not read: card colours already mean node type, defence status and missing value (§2).
- Labelling requires `update`, like every other node field; `is_public` grants read only.

The **filter bar** (§5) offers a tag filter over these labels.

### Three offers on an empty title

A draft (§3.3) opens with an empty title, and **three** different offers sit under that one field. They are separate in the interface and in the code:

| | **Link, don't duplicate** | **From template** | **AI suggestion** |
|---|---|---|---|
| Source | the assessment's own nodes, searched | a template's nodes of the child type, picked | the local model, asked for **one** |
| Chosen item | links the draft's parent to the existing node; nothing is created | fills the draft's title and description (and a defence's transfer flag) | fills the draft's title and description |
| Trigger | typing | a button, pressed | a button, pressed |
| Named in code | `suggest*`, `SuggestionList` | `template*`, `TemplatePicker` | `assistant*`, never `suggest*` |

**The two buttons are offered only while the title is empty, and only to someone with `update`.** Once the person types, a button that would replace their words is in the way; the buttons, and an open template picker, return if the title is cleared. Neither writes anything: both fill the form and show a note saying where the words came from, and the node exists only when the person presses Add.

**The AI suggestion asks for one and fills the form directly.** The server scales the token budget to the count (`Suggester::budgetFor()`, docs/ASSISTANT.md). A suggestion that arrives after the person has started typing is discarded, and an empty answer says so rather than leaving the form silently unchanged.

**The button is pressed, never automatic.** A suggestion costs CPU seconds, so it is not generated for every draft.

**It is offered only on a draft**, never on an existing node.

**It is absent, not disabled, where the assistant is unavailable.** From template is always offered to an editor; with no templates, the picker says so.

**Filling the form is not accepting it.** The accept step is the Add button, pressed by a person who can see the text and the note that a model wrote it, through the same validated write path a typed title takes. Only `title` and `description` are read from a suggestion, and it renders as plain text: it sits in an `<input>` and a `<textarea>`, and the codebase has no HTML renderer for generated output.

### Saving the title and description

The Details fields autosave, and the title commits on every keystroke. Two rules make that safe (`tests/js/stores/nodeWriteOverlap.spec.ts`):

- **One write in flight per node.** A keystroke typed while a request is out does not start a second request; it merges into a single follow-up that goes when the first response lands. Otherwise the second request would carry an `updated_at` the first had already superseded and the server would answer 409, showing a conflict banner to a user who was only typing quickly. Writes to *different* nodes are independent.
- **A response never overwrites what has been typed since it was sent.** The server's row is applied to the store, and the keystrokes accumulated since are re-applied on top of it. Otherwise an echo one round trip old would write itself into the field being typed in.

Neither rule narrows §3.5. A genuine second editor still moves the server's row under a single write, that write still comes back 409, and the banner still offers Keep mine / Take theirs.

**Structure is edited by dragging, not by a tab.** A cross-parent drop asks *Move or Link* (§3.7). What dragging cannot do (detaching one of several parents, and reaching a parent that is scrolled away or filtered out) is done from the **parent counter** for the `risk_attack` edge and on Strength for `attack_defence`. Detaching the *last* parent is refused with a reason: it would hide the node rather than delete it.

### The parent counter's popover

The `shuffle` counter on an attack's card says how many places it appears in. Pressing it opens **Appears under**: the risks this attack belongs to, a detach action per risk, and a type-ahead that links it under another risk. It is the only place in the application that can detach an attack from one of several risks (no card carries an unlink action, and dragging moves an edge rather than removing one).

- **Detach is offered only when there is more than one parent.** With one, the panel says why: removing it would leave the attack with nowhere to show, so delete it or link it elsewhere first. An orphan is a state the engine reports on and the tree cannot render.
- **Reading is not editing.** Someone without `update` still sees where the node appears; the detach buttons and the combobox are absent, not disabled.
- **The panel is anchored, not nested.** Every surface the counter sits on (the modal body, the chart canvas, the tree scroller) clips its children, so the panel is rendered outside them and positioned from the trigger's measured rect, as the combobox inside it is. "Click outside to close" therefore names the trigger, the panel *and* the combobox's own listbox, since none of the three contains the others.

The modal is **non-blocking over the tree**: the tree stays visible and the modal is dismissible with `Escape`. It never becomes a route; deep-linking to a node uses `?node=…&tab=…` query parameters, kept current with `history.replaceState`, so opening or switching tabs does not add browser history entries.

### The shell

**The header carries the type as an icon, left of the title**, in the type's own colour, with the type name as both its accessible name and its tooltip. **Delete** is in the header's actions group (and asks for confirmation).

**Panels are lazy, and stay mounted once opened.** A panel is created the first time its tab is selected and hidden rather than destroyed afterwards. Both halves matter:

- Four panels fetch on mount (History, Comments, References, Statistics), so mounting all of them up front would cost four requests every time anyone opened any node, and an error in one during render would take the whole tab subtree down with it.
- Destroying a panel on every tab switch would lose an unsent comment draft and refetch a history that had not changed.

**A modal open on a node the store does not hold says which of two things happened** and never renders an empty body: *Loading* when the store has not been hydrated yet (a `?node=` deep link arriving early), or *gone* when the store is populated and this uuid is not in it (a node deleted since the link was shared).

**The tab strip wraps; it does not scroll** (`flex-wrap`). A horizontal scroller hides tabs behind an edge with no affordance saying so, and a defence has eight tabs. The single line under the strip is the container's own bottom border; each tab's thicker underline is the selection marker. Arrow keys (and Home/End) move between tabs in **strip order**, not visual row order, with exactly one tab tabbable at a time.

**Type-ahead popups escape the modal's scrolling body.** The comboboxes on Details ("Appears under") and Strength are the last element of their section, and the modal body is a scroll container, which *clips* an absolutely-positioned child. The listbox is therefore teleported to `body` and anchored to its input by measurement, flipping above the input when there is no room below. Two consequences are part of the contract: the input names its listbox and its active option by id (`aria-controls`, `aria-activedescendant`), since containment no longer expresses either; and "click outside to close" means outside *both* the input and the popup.

### Value tab by type

**Risk — Impact.** A mode toggle (quantitative / qualitative). Quantitative shows a currency input; qualitative a level picker showing each band's label and range. **The field label reads "Loss each time this occurs"**, with helper text distinguishing it from annual loss ("Impact is per-occurrence, not annual loss"). This is the most consequential input error available in the tool.

**Attack — Likelihood.** Mode toggle. Quantitative shows a rate input labelled **"How often per year"** (suffix `/yr`), with a live hint: `0.5/yr ≈ 39% chance of at least one occurrence this year`, computed as `p = 1 − e^(−ARO)`. Values above 1 are legitimate and are not rejected.

**Defence — Cost.** Two fields, one-off cost (capex) and annual cost (opex), with the annualised total shown beneath.

**Status and the transfer flag are on Details**, where the rest of what a node *is* lives: status as a three-way segmented control, transfer as a toggle that changes the icon and label.

**Strength is not on the Cost tab.** It is per link and is the Strength tab's entire subject. The Statistics tab repeats the per-edge list beneath the figures it explains, as a read-only table.

### Scales tab

The root node *is* the assessment, so the settings that say what its figures mean sit on it: **currency**, **risk tolerance**, **amortisation period**, and a read-only summary of the three qualitative scales (impact, likelihood, strength) with a link to the calibration screen (§8).

**One controller, one FormRequest, two screens.** The tab reads `AssessmentSettingsController::edit` and writes through `update` and `UpdateAssessmentSettingsRequest`, the same endpoints the assessment settings page (§8) uses. There is no second endpoint and no second validator for these columns. Two consequences of reaching a page controller from a modal are part of the contract:

- **The read is an Inertia request made by hand**, on first activation of the tab. `Assessment/Show` is not sent the currency list or the amortisation cap (only the settings controller has them), and putting a config array on every editor page load for a tab most sessions never open is the wrong trade.
- **The write does not follow the controller's redirect.** That redirect suits the settings page and would replace the assessment with a settings screen from here. The store is patched locally instead, and a changed amortisation period triggers a recompute, because it is the one setting on this tab that moves a figure.

**Bands are calibrated on their own screen.** The tab summarises and links; §8's editor owns ordering, bounds and overlap.

### Statistics tab

Read-only, one table, three columns: **Inherent · Current · Target**, in that order, always. This is where the baseline vocabulary lives, because no card shows a baseline-dependent figure. Row definitions are in `Components/NodeModal/tabs/statistics.ts`, a pure function of the engine's own `CalculationResults`.

| Type | Rows |
|---|---|
| root | total risk; reduction against base in currency and in percent; a sentence for the current-to-target gap that no column can show; a coverage note (pairs valued of pairs total) |
| risk | annual loss; share of the assessment total; reduction against base |
| attack | annual loss; likelihood entered; residual likelihood; reduction against base |
| defence | *if switched off, loses* (`MC`); *if done, would save* (`MCadd`); *share of reduction* (`Attr`); *net annual benefit*; *risk removed per unit spent* (`CBR`); plus a non-baseline cost block (recurring per year, one-off, annualised total) and the edges it applies to |

Rules the tab keeps:

- **Every figure comes from the engine's own output.** No arithmetic beyond subtracting one baseline's field from another's.
- **`MC` and `MCadd` are separate rows, never collapsed.** They answer different questions and at most one applies per column ([calculation-engine.md §9.3](calculation-engine.md#93-the-addition-delta--for-planning)).
- **Missing renders as the `?` chip, never as `0`.** Three cases carry real meaning: a shelved defence's `attribution`, an unpriced defence's `CBR`, and a share of a total that is itself zero.
- **The inherent column's reduction-against-itself is `—`, not `€0`**, which would read as "the defences achieved nothing".
- **Nothing here is editable**, and nothing here is a number a human typed.

### Reduction target

On the Strength tab, **strength comes first, then the reduction target, then the rationale** (maximum 500 characters). The reduction target refines a reduction that has to exist before it can be refined, and it does not move the assessment total.

The per-link reduction-target selector offers **Reduces likelihood / Reduces impact / Reduces overall loss (default)**, with blank meaning the default. Beneath it, permanently:

> This choice affects the intermediate figures shown on the attack and risk — the residual likelihood and the retained impact — but not the assessment total, because all reductions multiply together.

---
## 8. Dashboard, assessment list and creation

### Dashboard

`/dashboard` is where Laravel sends people after login and where the sidebar's home link points. It answers four questions someone has on arrival: what is assigned to me, who mentioned me, what do I own, and what have I been invited to.

The page has two sections of two tabs each:

| Section | Tabs |
|---|---|
| **Notifications** | Assigned to me · Mentions |
| **Assessments** | Mine · Shared with me |

Work comes before inventory: Notifications is the first section and *Assigned to me* its first tab. Each section opens on its first tab.

**The counts sit on the tab labels.** A count that is hidden until the tab is clicked is a notification nobody sees. The number is plain text inside the tab button, so it is part of the tab's accessible name rather than a coloured pill a screen reader skips, and an optional `countLabel` (visually hidden) says what it counts when the label alone would not ("unresolved"). A zero draws nothing.

- *Assigned to me* shows the number of rows in the excerpt (at most six).
- *Mentions* counts only **unresolved** mentions; a thread that has since been closed is not waiting for anybody.
- *Mine* and *Shared with me* show the real totals (`DashboardData::counts()`), not the length of the six-row excerpt.

`DashboardTabs` owns the tablist. `DashboardSection` has a `section` variant (a `<section>` with a heading) and a `panel` variant that drops its heading and landmark, because inside a tab panel the tab is the accessible label (`aria-labelledby`) and an `<h2>` as well would announce the same name twice. Panels are `v-show`, not `v-if`, so every panel's empty state exists for anything reading the page as a whole. The tablist implements the full ARIA Authoring Practices tabs contract: `aria-controls` and `aria-labelledby` wired both ways, a roving `tabindex` (one tab in the tab order), Left/Right/Home/End to move, and focus following selection (automatic activation).

The four lists:

1. **Assigned to me** — nodes assigned to the user, newest update first, each with the assessment, the node type, the title and *what the node still needs*: `needs an impact` on a risk without an impact, `needs a likelihood` on an attack without a likelihood (a mode never chosen counts as missing, and so does a chosen mode with no value under it; this mirrors the `?` badge in the node modal). A defence never carries a "missing" note: strength lives on the `attack_defence` edge, so one defence can be complete against one attack and blank against another, and a single word on a row cannot say which. The query is scoped to assessments the user currently owns or is a member of, independently of the hook that clears assignments when a collaborator is removed. The title links to the node in the editor (`?node=<uuid>`).
2. **Mentions** — comments that mention the user, unresolved first, then newest. Rows are read from the `MentionedInComment` database notifications already sent, not by re-scanning comment bodies: `MentionParser` resolves who was meant once, at write time, against the assessment's collaborators only. The `resolved` flag is read live from the comment, because it is the one field that changes after the mention. The link opens the node's comments tab (`?node=<uuid>&tab=comments`).
3. **Mine** — owned assessments, most recently updated first.
4. **Shared with me** — assessments owned by someone else in which the user is a member, with the **owner's display name** on every row.

Each panel is an excerpt of six rows. *Mine* has "See all" to `/assessments`; *Shared with me* has "See all" to `/assessments/shared`. The two Notifications tabs have no "See all", because there is no page behind them.

**Every panel has an empty state** that names what will appear there and why: "Nothing assigned to you", "No one has mentioned you", "No assessments yet", "Nothing shared with you".

The data comes from `App\Domain\Dashboard\DashboardData` (`for()`, with `assignedToMe()`, `mentions()`, `myAssessments()`, `sharedWithMe()`, `counts()`); `Dashboard.vue` only renders it.

**Scoping.** Owned and shared only. A public assessment is reached by its link and is never advertised on a stranger's arrival page.

### Assessments shared with me

Route `assessments.shared` — `GET /assessments/shared` (`auth`, `active`, `throttle:authenticated`) — titled *Assessments shared with me* and reachable from the sidebar and from the dashboard tab's "See all". The route is registered before `assessments/{assessment:uuid}` in `routes/web.php`; the wildcard route would otherwise bind `shared` as a uuid and answer 404.

It is a real collection with its own empty state: "Nothing shared with you" is a different statement from "you have no assessments", which `/assessments` says.

The page renders the same `Components/Dashboard/AssessmentList.vue` as *My assessments* — the same filter, the same rows, the same per-row actions, each gated on the permission the server computed for that row — so the two lists of the same object cannot disagree about what can be done with a row. Rows are built by `App\Domain\Dashboard\AssessmentListRows` for both pages. Every row shows "Shared by <owner display name>"; the owner's email address is never sent. The list is unpaginated.

**Two guards, in this order.** The query is scoped by `assessment_user` membership first (and `owner_user_id != user`), which decides whether a row exists on the page at all. `AssessmentPolicy::view()` then re-checks every surviving row, because every route touching an assessment goes through the policy. The policy pass cannot run alone: `is_public` grants read to whoever holds the link ([security.md §3.1](security.md)), and so does being a template, and neither is a reason to advertise an assessment on a stranger's page. Run second, the policy can only remove rows, never add one. Both live in `DashboardData` (`sharedWithMe()` for the dashboard's six rows, `sharedWithMePage()` for the whole list) and share one query builder, so the two surfaces cannot disagree about what "shared with me" means.

### List

`/assessments` (*My assessments*, `assessments.index`) lists the assessments the caller owns, newest first, unpaginated, with a **New assessment** button. Assessments shared with the caller are on their own page, above.

- **Rows** — title, description (two lines), a *Public* badge, the **root node's labels**, and "Shared by <owner>" on rows the caller does not own. Only the root's labels appear; labels on any other node stay inside the editor, or a row would carry a hundred chips.
- **Filter** — a search field above the list (shown when the list is non-empty) that narrows the rows already on screen, client-side, matching title, description and label case-insensitively as a plain substring. No request: the list is unpaginated, so a round trip would be slower and no more correct. A filter that matches nothing says "No assessments match that" and does not fall back to the "no assessments yet" empty state, which would read as if the user's assessments had been lost.
- **Per-row actions** (icon buttons with the name as tooltip and accessible name) — **Settings** (`manage`), **Share** (`manage`), **Export**, **Duplicate** (`view`), **Delete** (`delete`, with a confirmation dialog naming the assessment and the retention period). Export opens the same `ExportMenu.vue` as the editor's action bar (`Components/Toolbar/AssessmentActions.vue`), so there is one export path whichever screen it is opened from. The row and the action bar offer the same verbs for the same object, minus History and Print, which have no meaning outside one assessment's own view. The Share icon links to the `share.index` route; the editor's own Share dialog ([§10](#10-admin-and-profile)) is the sharing UI.

**Every action renders from a permission the server computed** (`can_manage`, `can_delete` from `AssessmentPolicy`), never from ownership: a manager who is not the owner can share, and an admin who is neither can delete. Hiding a button is a courtesy; the route is the guard, and both halves are asserted in separate tests.

**Duplicate is gated on `view`, not `manage`.** The clone touches nothing in the source and lands in the actor's own ownership, which is the same reasoning `templates.use` follows. It goes through `AssessmentCloner` and renames the copy's root title to "… (copy)" (truncated so the suffix fits the 255-character column), because the cloner reproduces the title verbatim, which would leave two identical rows in the list and in the sidebar. The button disables itself while the request runs, since cloning a large assessment is not instant. The user is redirected back to the list.

**Delete is soft.** `DELETE /assessments/{uuid}` (policy `delete`) moves the assessment to *Deleted*, a collapsible section below the list (opened automatically by `?show=deleted`). It lists every deleted assessment the caller could restore — their own, those they manage, every one for an admin — with the deletion date and the date the daily purge will erase it (`deleted_at` plus `trash_retention_days`, default 30). **Restore** (`POST /assessments/{uuid}/restore`, policy `delete`) returns it to the editor. **Erase permanently** (`DELETE /assessments/{uuid}/erase`, policy `forceDelete`: the owner or an admin; a manager can delete and restore but not destroy) is offered only from *Deleted*, behind a confirmation, so erasing is always two deliberate steps. A live assessment answers 404 on restore and erase. See [security.md §12](security.md#12-data-protection) and [schema.md](schema.md) for retention.

**Create.**

- **Blank** — the *New assessment* dialog on `/assessments` (also opened by the sidebar's "New assessment", which links to `/assessments?new=1`, so there is one creation flow and one write path, `assessments.store`). Fields: **Title** (required; it names the target of assessment), **Description**, and a **Scale** dropdown listing the admin-defined scales, Standard first. On success the user lands in the new assessment's editor.
- **From a template** — the *Templates* page (`/templates`) lists every template; each card can be opened read-only to see what it contains, and **Use this template** creates a private copy owned by the current user and opens it.
- JSON export files are not re-importable through the interface; the JSON export ([§9](#9-reports)) is for backup and interchange.

**Settings** (manager role) — currency, risk tolerance, amortisation period, scales; described next. The public flag, share link, collaborators and ownership transfer are in the Share dialog.

### Assessment settings

`/assessments/{uuid}/settings` (`assessments.settings.edit`, form saved with `PATCH assessments.settings.update`), gated on **`manage`**, not `update`. An editor may change every value in the assessment and still not this: these settings decide what those values *mean*. The screen is reachable from the editor's action bar (`Components/Toolbar/AssessmentActions.vue`, shown only with the `manage` ability — a courtesy; the route refuses independently) and carries its own **Return to assessment** link, top right.

**No title or description here.** They live on the **root node** and are edited there, in the tree, so there is one editor for each field and one history entry per edit. `UpdateAssessmentSettingsRequest` marks both fields `prohibited`, so a scripted request cannot write them either.

| Setting | Effect |
|---|---|
| Currency | **Display only.** Symbol and number formatting. No conversion, no rates; the figures stay exactly as entered. Validated against the fixed list in `config/arguesecure.php` (`currencies`), never a free string, because the value reaches `Intl.NumberFormat`, which throws on an unknown code and would blank every figure on the page. |
| Risk tolerance | **Display only.** An annual-loss threshold, numeric, `0` to `999999999999999.99`. Nullable: "not set" and "zero" are different statements and zero is legitimate. An empty box is sent as `null`. |
| Amortisation period | The one setting here that **does** move a computed figure: `DefenceRanker` spreads capex over it, so every annual cost, cost-benefit ratio and ROSI changes. Integer, 1 to `max_amortization_years` (default 10). Changing it bumps `calc_version` so cached results are recomputed; the other two deliberately do not. |
| Scales | Impact, likelihood and defence-strength bands, edited in three cards below the form under a **scale** dropdown, with one Save for all three. See "The scale cards". |

Saving the form records the before and after values of currency, amortisation period and risk tolerance in the revision log (`RevisionRecorder::recordSettingsUpdate`), so a historical reconstruction restores the divisor along with the nodes ([schema.md §3.9](schema.md)).

**Where the tolerance appears, and what it never does.** It appears as a badge on the target-of-assessment card beside the totals ("€15,000 over tolerance" / "Within tolerance"), compared against the **current** baseline (the question is whether today is acceptable, not whether a plan would be), and as a dashed threshold line on the report's baseline chart, which widens its own scale to keep the line on the chart. It states a comparison and never a verdict: it does not recolour bars, re-rank defences or label anything high or low, because [calculation-engine.md](calculation-engine.md) forbids band-mapping a computed figure and a user-supplied threshold does not license it. The display-only claim is asserted in `tests/Feature/Assessment/SettingsTest.php` by comparing the totals figure for figure before and after.

### The scale cards

Three cards at the foot of Assessment settings — **Impact**, **Likelihood** and **Defence strength** — outside the settings form, so a change of bands is never tied to a currency change. Each card is a table of bands with **label, lower bound, upper bound and representative**, and a note under the table: a value exactly on a boundary falls in the **higher** band.

**One save, all or nothing.** `PUT /assessments/{uuid}/scales` (`assessments.scales.update`) carries all three kinds in one request; `AssessmentScaleApplier` applies them in one transaction. It is gated on **`manage`**, because editing a scale changes every number computed from it ([calculation-engine.md §8.4](calculation-engine.md#84-properties-to-keep-in-mind)); the save bumps `calc_version` through the recalculation observers. A band table allows 1 to 12 bands per kind; a label is at most 64 characters.

**Choosing a scale.** The dropdown lists every admin-defined scale (built-ins first, then custom ones by name) and **Custom** last. Choosing a scale *overwrites the editors' contents without saving anything*; a line under the dropdown reads "Not saved yet." while the editors differ from what is stored. Any edit — a label, a bound, a representative, adding or removing a band — switches the dropdown to Custom at once. **Save scales** stores the editors' contents. The server alone decides which scale the stored bands equal (`ScaleSetMatcher`), so Custom bands cannot be labelled Standard. The dropdown starts on `assessments.scale_set_key` and keeps naming a scale after an admin later edits it. The assessment keeps its own copy of the bands and is never repointed at the shared rows. There is no separate "reset to default": choosing a scale again is the reset. The save reports "Scales saved." and, when a removed band had stored values, how many were cleared.

**What the editor shows.** The band **code** (`scale_levels.code`) is internal: derived from the label when a band is created, then frozen, and shown nowhere. It travels in the payload as an opaque round-trip token the editor sends back untouched, which is what keeps it stable across a rename. An id cannot do this job, because a custom scale is cloned per assessment and its level ids do not exist until the clone runs, while a catalogue fixture is shipped once and must resolve in all of them. A band the editor creates carries `null` and is named server-side by `LevelCodeDeriver`, which resolves collisions against `(scale_id, code)`. Band **colour** (`scale_levels.color`, default `#9CA3AF`) is not editable or shown. There is no live-preview calculator and no "using the system defaults" warning; the report cover marks a scale that is still on shared bands, and the engine's `uncalibrated_scales` advisory keys on the same `is_system` flag.

**The invariants are made unreachable by construction.**

- A band's upper bound and the next band's lower bound are one number, held once and rendered twice, so the no-gap/no-overlap invariant cannot be broken by typing. The first band's lower bound is always 0 and the last band is open-ended ("and above"; a fraction scale closes at 1).
- Adding a band **splits** an existing one, so the outer bounds never move.
- Removing a band gives its range to a neighbour, so `[0, ∞)` stays covered. A gap would make the reverse lookup partial, and a value falling in the hole would read as missing and silently leave the total.
- Moving a boundary is clamped inside its neighbours and recomputes both representatives. A representative outside its band is flagged ("Must fall inside this band, or the value will not map back to it."), and a customised band shows a suggested representative.
- `ScaleValidator` checks all invariants server-side and is the authority; its messages name a band by **label**, never by code. It should never fire from these cards.

**Ordinals are parked before they are renumbered.** `(scale_id, ordinal)` is unique, so removing or reordering a band cannot renumber rows one at a time: the first write would collide with a row not yet deleted. `LevelOrdinals` pushes the existing range clear in one statement first.

**Clone on edit.** A system scale is shared by every assessment on the instance, so the first edit copies it into the assessment and repoints the assessment's foreign key; editing in place would silently move every figure in every other assessment using it. Stored values (`nodes.impact_level_id`, `nodes.likelihood_level_id`, `attack_defence.strength_level_id`) are re-pointed **by code** across the copy. A removed band has no new row to point at: the values that referenced it are cleared into the legal "qualitative, nothing chosen yet" state and **counted** in the confirmation message.

### Reading a saved version

Route `/assessments/{uuid}/snapshots/{id}/view` (`assessments.snapshots.view`, `active` middleware), gated on `view` — the same ability as the snapshot list and the snapshot's figures page, because it renders the same decoded graph those serve.

**It mounts `ChartView` and `TreeNode`, the live editor's own components**, against a store built from `snapshots.graph` by `SnapshotViewPresenter`. A second read-only renderer would drift: a tier would gain an indicator on one side only. The presenter mirrors `AssessmentController::nodeToArray()` key for key, and `tests/Feature/Snapshots/SnapshotViewTest.php` compares the two key sets so drift fails.

Read-only is enforced twice:

- **Every ability is false** (`comment`, `update`, `manage`, `transfer`), which removes each add, delete and edit affordance. This gates what is *offered*.
- **The store is built `frozen`**, which turns every method that would reach the network into a no-op. This is the stronger half: `loadUnresolvedCommentCounts()` fires from `onMounted` with no ability check, and the label ids on a frozen graph are minted for the render rather than being real rows, so a write that escaped the first half would attach an unrelated live label to a node.

Deliberate absences:

- **No node modal.** Its tabs read live comments, history and references; showing today's conversation against a version it did not happen on would mislead. The cards hide the control rather than leading nowhere.
- **No comments, presence or locks** — they are live state and are not in a snapshot. A note at the foot of the page says so once.
- **No Report position in the view switcher.** The page offers a Chart/List control only; a report route serves the *live* assessment.
- **Nothing is recomputed.** `results` is served from the snapshot exactly as stored.

A sticky banner reads "Viewing a saved version" with the version's name, date and author and a *Read-only* chip, with links to the version's **Figures**, **All versions** and **Back to the current version**. **Restore this version** is offered to users who can restore (`update`), behind the same warning dialog the version list uses. Zoom, view mode and collapse state persist under a key namespaced by snapshot id (`snapshot:<id>`); namespacing by the assessment uuid would let reading an old version rewrite the reader's state in the live editor.

---

## 9. Reports

Route `/assessments/{uuid}/report` (`assessments.report.show`), readable by anyone who can `view` the assessment, including a public-assessment reader or a share-link session. Report options are query parameters, parsed and validated by `ReportOptions::fromQuery()`:

| Parameter | Values | Default |
|---|---|---|
| `baseline` | `current`, `target`, `both` | `both` |
| `include_shelved` | boolean | off |
| `include_comments` | boolean | off |
| `include_methodology` | boolean | off |
| `include_warnings` | boolean | off |
| `show_assignees` | boolean | off |
| `omit_sections` | comma-separated section ids (or an array) | none omitted |
| `download` | boolean; `1` forces the full report | off |

Unknown parameters are ignored, unknown section ids in `omit_sections` are dropped, and an invalid `baseline` falls back to `both`. Defaults are the same on the page and in every export.

**There is one way of showing figures, and it is not an option.** [calculation-engine.md §8.3](calculation-engine.md#83-which-scale-for-which-quantity) fixes which displayed quantities have a scale at all: impact, likelihood and strength. Every ALE, and with it every cost, CBR, ROSI and net-benefit figure, is rendered as currency, and §11.2 step 8 forbids band-mapping ALEs. No setting could stop the report printing currency in every register row, ranked list and programme figure, and a "qualitative only" switch would say something false about the document. A value renders as the level its author chose where `impact_mode` / `likelihood_mode` is `qualitative`, and as the amount they typed where it is `quantitative`; that rule is per node (§3.2: the chips show *typed* values, computed figures keep their own treatment), so a report of a qualitatively elicited assessment already reads as levels. False precision is addressed by [calculation-engine.md §8.4](calculation-engine.md#84-properties-to-keep-in-mind) pitfalls 3 and 4 — an order-of-magnitude range beside the figure and a persistent advisory on an uncalibrated scale — rather than by withholding the numbers a report exists to deliver.

**Sections**, in document order (ids as used by `omit_sections`, `ReportOptions::SECTIONS`):

| Id | Section |
|---|---|
| `cover` | Cover: target of assessment, owner (name only), generation time, currency, how many risk/attack pairs are counted in the total, and a marker for a scale still on shared bands |
| `written-summary` | Written summary — the author's prose (see below) |
| `executive-summary` | Executive summary — annual expected loss under each chosen baseline, with the implemented-controls and cost counts |
| `programme` | The plan: cost and benefit — what the todo controls cost, annualised and split into one-off and recurring, against the risk they remove in currency, and the return that ratio implies. Controls with no cost recorded are counted beside those totals and never folded into them: an unpriced control is not a free one. |
| `risk-register` | Risk register |
| `defences-importance` | Defences ranked by cost benefit (with a share-of-reduction chart; one switch covers both) |
| `defences-opportunity` | Defences ranked by opportunity (todo controls, by what implementing each buys against the current baseline) |
| *(shelved)* | Shelved defences, listed only — governed by `include_shelved`, not by `omit_sections` |
| `transfers` | Transfers |
| `control-coverage` | Control coverage |
| `tree` | Full assessment |
| `references` | Appendix: references |
| *(appendices)* | Comments (`include_comments`), model-quality warnings (`include_warnings`), methodology (`include_methodology`) |

Charts are **inline SVG** — no canvas and no bitmap-rendering chart library — so they survive printing at full resolution.

**The full-assessment section is the list view on paper**: every node with the values a person entered *and* the ones the engine computed, under inherent, current and target, down to each defence's strength on that link, its reduction target, its rationale and all three cost figures. A missing value prints as a dash.

**Control coverage** follows Transfers when any defence cites a framework control ([schema.md §3.12](schema.md)). Per framework, in config order, it lists each cited control in natural order with a status — *implemented* when any citing defence is implemented, *planned* when none is but one is todo, *shelved* otherwise — and the defences citing it, each with its own status and a note when it counters no attack. It lists only what is cited and never says "compliant": a report is not a compliance certificate ([PRODUCT.md](../PRODUCT.md)). `include_shelved` binds it as it binds the shelved ranking: when shelved-only controls are dropped, the section says how many, and a framework left with nothing to list is not drawn as an empty card. With no citation there is no section, in every format.

**Omitted sections are named.** A report that leaves any section out says so in every format ("Sections left out of this report: Programme, Transfers."). Leaving the warnings appendix out is stated the same way: with `include_warnings=0` the appendix is replaced by "N model-quality warnings were left out of this report" when the engine raised any, so the absence of the section is never read as "none raised". Hiding a section never changes a figure in another. `omit_sections` is ignored by `download=1`.

### The report is the third view

The editor toolbar's view switcher reads **Chart · List · Report**, and the report page renders the same switcher with Report selected, so the way back is the control that brought you.

**Report is a route, not a persisted view mode.** `useEditorViewMode` stores only `chart | list`; the third position is derived from the current route. Persisting it would make a detour into the report the view the editor opens in next time. Choosing Chart or List *from* the report writes the persisted mode before navigating, so the editor opens in the view chosen — including for an anonymous share-link reader, who is the `anon` case the storage key already carries.

**The options row is always visible**, immediately beside the search box. Changing an option re-requests the page with a new query string.

**Two chromes, chosen on whether a user is signed in.** A share-link guest has no `{name, email}` user for `AuthenticatedLayout` to render, so wrapping the page in it unconditionally would break every share link. An authenticated viewer (owner, collaborator, admin, or a signed-in reader of a public assessment) gets the sidebar shell (`AuthenticatedLayout`, [§2](#2-design-system)); a guest gets the bare page. Both branches mount the same `Components/Report/ReportDocument.vue` and `Components/Report/ReportOptionsRow.vue`, so the content and the search/options/zoom row cannot drift; only the surrounding chrome differs.

**`AssessmentActions` is mounted for an authenticated viewer only.** The bar bundles Settings, History, Share, Export and Print, gates Settings and Share on `manage`, and History carries no ability gate of its own because wherever the bar renders, the viewer is authenticated with at least `view`. A guest would be bounced to a login page for an assessment they were never asked to sign in to see, so the guest branch does not mount it; Export and Print appear as the page's own controls, since neither route carries `auth` middleware.

**Search and zoom.** The shared `SearchBox.vue` ([§5](#5-canvas-search-and-focus)) searches the report's full-assessment section (titles, descriptions and defence rationale; never a computed figure). Report zoom (`Components/Report/ReportZoomControls.vue`, Ctrl+scroll and Ctrl +/−/0) scales the document's type size with the CSS `zoom` primitive the list view uses, applied to the report body only; the chrome above it and the print output are untouched.

### The chrome above the report

**The switcher occupies the same screen position in chart, list and report**, so switching is a change of content and not of layout. It is the same chrome, not a matching one: all three pages render `Components/Chrome/*` ([§2](#2-design-system)), so the header band, the title row and the toolbar band have one definition each.

- **Vertically**, the switcher sits at the same y and height in all three views.
- **Horizontally**, it sits at the same inset from the right edge.

Both are asserted in a real browser (`tests/e2e/10-report.spec.ts`), because a pixel claim in prose is not a guarantee.

**The switcher is last, and that is the mechanism.** `ChromeToolbarBand` puts it in an `anchor` slot after a `trailing` slot. Each page fills `trailing` with its own zoom controls (`Components/Toolbar/ZoomControls.vue` in the editor, `Components/Report/ReportZoomControls.vue` on the report, whose wording does not mention chart or list size), and neither reserves space for the other. The switcher's distance from the window's right edge is the band's own padding and nothing else. The left edges are not comparable: the editor's band is inset by the sidebar gutter and the report's guest branch is not.

**The report's settings sit behind two buttons beside the search box**, each group under a heading that says what it decides:

- **Defences** — *Which defences the report covers*: *Implemented defences* (always on), *Planned defences, with the target figures* (on is baseline `both`; off is `current`), and *Shelved defences, listed only* (`include_shelved`).
- **Sections** — *Report body* (one switch per body section above), *Appendices* (references, comments, methodology, model-quality warnings) and *Detail* (*Show assignees*, default off; no export turns it on for you, because a report leaves the room and who is responsible for which line is internal information the exporter has to be told to include).

The body and references switches are one list in the query, `omit_sections`. Neither button shows a count. There is no Filters button on the report: these are server inputs that change the document, not client filters that dim nodes.

### Export: one menu, five formats

Every export is one **Export** menu, as a button in the report chrome and the editor's action bar and as an icon on each assessment-list row. It offers, in order:

| Menu item | Route | What it is |
|---|---|---|
| Web page | `assessments.report.export-html` (`export.html`) | One self-contained HTML file: inline CSS, no script, no external asset. The no-CDN rule binds a file that leaves the server as hard as a page served by it, since this one is opened from a mail attachment on a machine that has never met the deployment. |
| Word document | `export-word` (`export.docx`) | The prose sections in order, with the tables, header rows repeating across pages (`tblHeader`). |
| Excel workbook | `export-excel` (`export.xlsx`) | One sheet per section, headers frozen, figures written as **numbers** with the currency in the cell's number format, so totals work. |
| Spreadsheet data | `defences-csv` (`defences.csv`) | Every section as CSV — risks, attacks, defences **and links**. Strength lives on the `attack_defence` edge, so a defence-only file could not say that one control is 80% effective against one attack and 20% against another. |
| Data interchange | `export-json` (`export.json`) | The whole assessment as JSON, for backup or another instance. Its shape is a stable interchange contract. |
| Everything expanded | `export-html?download=1` | The forced-full render (below), as a web page. |

All five are `GET /assessments/{uuid}/report/<file>`, readable by anyone who can `view`, rate limited as report generation ([security.md §9](security.md#9-rate-limiting)). They are built on `phpoffice/phpspreadsheet` and `phpoffice/phpword`, both LGPL and self-hosted. **PDF is not an export format**: it is the browser print pipeline below, so no PDF library or headless-Chromium container is part of the deployment. A `.xlsx` faked as CSV, or `.docx` faked as HTML, opens with a repair dialog, which is worse than not offering the format.

**The exporters read one assembled report, never the database.** `ReportAssembler` produces the figures, `ReportSectionBuilder` arranges them into ordered sections, and each exporter only knows how to write a heading, a paragraph and a table in its own format. A second source of truth for the same figures would eventually disagree.

**`ReportOptions` binds all five formats.** An export carries the options the options row currently shows (the editor's chart and list views, which have no options row, export with the defaults above), so the file a reader takes away is the document they were looking at. Every option the row offers is honoured in all five formats and on the page.

**Missing is a dash, in every format** (`Cell::MISSING`, `—`). Never `0`, never blank, never `∞`. A missing impact or likelihood excludes the pair from the total rather than contributing zero, and writing `0` would turn "we do not know" into "we measured it and it was nothing".

**Currency is the assessment's**, formatted against a fixed `en-GB` locale to match the on-screen report, never the server's locale, which would make an export read as a different number from the page it came from.

**Generation is synchronous.** A 40-control assessment (8 risks, 40 attacks, about 80 links) renders all five formats well inside a second or two each, and `tests/Feature/Reporting/ExportPerformanceTest.php` fails if any format becomes seconds slow. Nothing needs the queue.

### Written executive summary, and the drafted one

A **written summary** sits above the computed figures, because that is the order a report is read in: a few paragraphs in the author's words, then the numbers they refer to. It is an ordinary field of the assessment: anyone with `update` writes, edits and clears it. On deployments with no inference runtime that is the whole feature.

Where the assistant is available (all four availability conditions of [docs/ASSISTANT.md](../ASSISTANT.md), including the summary switch), a **Draft with the assistant** action appears beside it. It queues a job and returns immediately: generation takes minutes (roughly four on the recommended model for a 73-node assessment, growing with the assessment), so no request is held open. The finished draft arrives as a **notification** that links to the report.

**The draft is never the document.**

- It lands in its own column (`summary_draft`) and is shown in its own panel beside the live summary, not in place of it. A job finishing minutes after it was requested must not overwrite what the author has typed since.
- **Edit and use** opens the draft *in the editor*; saving is a separate, deliberate act. There is no one-click publish: the person reading the text is the only control that catches an instruction planted in a node description.
- The draft reaches only people who could accept it (`update`). A public reader or share-link visitor is not sent it at all: the server withholds it rather than the template hiding it. The written summary itself and its disclosure mark are sent to everyone.

**Disclosure is stated twice, and both print.** A line beside the summary says it was drafted by a local model and edited by the author; the methodology appendix carries the long form ("Machine-drafted text."), naming which section was machine-drafted and stating that the author is responsible for it. The mark (`summary_narrative_generated`) is **sticky**: accepting a draft sets it, editing the text afterwards does not clear it, and only clearing the summary clears it. Nothing records *which* model wrote the text; the text, the mark and the disclaimer all survive the assistant's removal, and the Draft action simply stops being rendered.

### Progress, and stopping

Every assistant generation reports what it is doing, and every generation can be stopped, because CPU inference runs for minutes and a spinner that says nothing is indistinguishable from a hang.

While one runs, the panel shows **elapsed time first**, then a bar drawn against the configured token budget, then a **Stop** button. The bar is labelled as a ceiling and never reaches 100% while work is still running: the budget is an upper bound and most generations finish short of it.

- **Stop stops the generation**, not merely the waiting. The request sets a flag the running generation notices on its next token, and the per-user lock is released on the way out.
- **A stopped generation is not an error.** No error message is shown and nothing is logged as broken.
- **The summary draft can be stopped while still queued**; the cancellation survives the wait and the job checks it before doing any work.
- **Scope differs by job.** Suggestions and help are the caller's own request: only they see and stop it (`assistant.generation.show` / `assistant.generation.destroy`). The summary draft belongs to the **assessment**: anyone who could have started it can see it and stop it (`assessments.assistant.summary.progress` / `summary.cancel`), so a long generation started by someone who has since left can be stopped by whoever remains.
- Progress is **polled every two seconds** on the ordinary authenticated rate limit (`throttle:authenticated`), not the assistant's inference limit, so watching a job does not exhaust the budget for asking about it. A failed poll changes nothing on screen. The routes exist only when the assistant is available.

### The forced-full render

`GET /assessments/{uuid}/report?download=1`, and the same parameter on every export route, produces the whole report with no options step. It is reached from the export menu's **Everything expanded** item. `download=1` is resolved in `ReportOptions::fromQuery()` (`ReportOptions::forDownload()`) and **overrides every other parameter** rather than merging with them: baseline `both`, shelved defences, comments, methodology and warnings included, no section omitted. **Assignees are the one exception and stay off**: everything else `download=1` forces shows more of the assessment, while assignees add people's names to a document about to leave the room, which has to be chosen rather than inherited. A stale link carrying `baseline=current` therefore cannot produce a document that looks complete while silently omitting the target state.

In that mode the page hides the options row and chrome entirely, sets the document title to `ArgueSecure — {title} — {YYYY-MM-DD}` (browsers derive the suggested PDF filename from it), and calls `window.print()` once on mount, guarded in `sessionStorage` so a back-navigation does not reopen the dialog.

### Print asks which view

**Print** is a small menu — *Chart*, *List*, *Report* — with the current view first. The current view prints in place. The other editor view is switched to client-side and printed after it has rendered. The report, and Chart or List from the report, are visited with a one-shot `?print=<token>` that the target page prints on arrival (`usePrintOnArrival`); the token is unique per click and spent once per session in `sessionStorage`, so a back-navigation does not reopen the dialog. This is not `?download=1`: the report prints with the options it is given, not forced-full. The user is left on the view they printed. A share-link guest on the report sees a single Print button.

### Print and PDF

**Print** calls `window.print()` against `resources/css/print.css`, loaded by `AuthenticatedLayout` and by the report page. It prints exactly what the options row is set to. The first time a browser uses it, the report shows a one-line hint, "Choose 'Save as PDF' as the destination." (remembered in `localStorage`).

- `@page { margin: 18mm 15mm; }`, with page breaks between report sections.
- `thead { display: table-header-group; }` so long tables repeat headers.
- `break-inside: avoid` on cards, table rows and chart blocks.
- Link URLs are expanded after their text.
- Light-mode colours are forced regardless of the viewer's theme.
- Navigation, actions and the options row are hidden.

This keeps a headless-browser container and any PDF library out of the deployment.

### Printing the chart and the list

All three views print through the same `print.css` and the same browser pipeline. Report rules are scoped to `.report-*` and editor rules to `.editor-print`, so neither can disturb the other's page breaks.

Common to both editor views:

- The sidebar goes with its **gutter**, so no blank strip is left down the left of every page.
- Search, the view switcher and the zoom controls go. The toolbar's **active-filter chips stay**, because they are the only on-screen statement of what the reader is looking at; their remove buttons print as words, not icons.
- Hover-only affordances go.
- A **running header** carries the assessment title and the date, so a printed chart is never a picture of an unnamed thing from an unknown date. The report has a cover page and does not get one.

**List** — the tree indentation is plain nested `<ul>` padding and survives untouched; `break-inside: avoid` applies per risk block and per node card; the scroll container releases its height.

**Chart** — the transform is reset, the connectors are SVG and scale with the page, and landscape is suggested through `@page { size: landscape; }`. That rule is teleported into `<head>` by `ChartView` while the chart is mounted, rather than living in the stylesheet, because `@page` cannot be scoped by a selector and the list view, taller than it is wide, wants portrait.

**The current zoom is ignored** in favour of a fixed print size in all three views. The chart applies zoom with `transform: scale()`, the list and the report with CSS `zoom`; all three are reset in `print.css`.

**A chart wider than one page tiles horizontally across several**; this is the browser's own behaviour. Scaling a whole assessment down to one sheet would print text nobody can read.

### Presenting the numbers honestly

The report does not overstate confidence:

- A partial total is prefixed with `≥` and its coverage stated.
- Every figure is labelled with its baseline.
- Marginal contributions are never presented as a decomposition; any "share of reduction" chart uses log-share attribution ([calculation-engine.md §9.5](calculation-engine.md#95-log-share-attribution--the-additive-metric)).
- The three cost buckets stay separate; a zero-cost control never prints `∞`.
- The methodology appendix, which states the independence assumption and the per-occurrence definition of impact, is one switch away and is on for `download=1`.

---

## 10. Admin and profile

**Admin** `/admin` (redirects to `/admin/users`), gated on `is_admin`. Its tabs are **Users**, **Instance settings**, **Authentication**, **Defaults**, **AI assistant** and **Security log**.

- **Users** — a table with search (name or email), sort (name, email, last login, created) and pagination; columns for name, email, admin flag, active flag, number of owned assessments, last login and created. Admins can create, edit, deactivate and delete users, set or reset a password (a generated password is shown once, on the next page only, and is not kept in browser history), toggle the admin flag, and remove a linked sign-in identity or a user's two-factor enrolment. Deleting a user warns about the assessments they own and offers to transfer them to another user instead; without a transfer target, owned assessments are deleted with the account (assessments already in the trash are transferred or erased with it), and the user's other sessions are revoked.
- **Bulk import** — the **Bulk import** button on the Users tab opens a dialog; the CSV goes through a **preview-and-confirm step** (a page) listing every row that will be created and every row that will be rejected with the reason, so bad rows are never silently swallowed. The file may be UTF-8 (with or without a byte-order mark), UTF-16 or Windows-1252, and comma-, semicolon- or tab-separated, as Excel writes them. After confirmation, a **credential sheet** (an HTML print view for workshops, printed with the browser's print function; no PDF library) lists each created account with its generated password. It is shown once: the passwords are never stored in plain text, never re-derivable and never emailed, and each user can be reset individually afterwards.
- **Instance settings** — about the *deployment*: whether public assessments are allowed, and the session lifetime (minutes).
- **Authentication** — how people sign in: the switches *Require two-factor authentication for password sign-in* and *Sign in with SSO only*, and the OpenID Connect provider's settings (issuer, client ID, client secret, sign-in button label, email domains that may create an account, whether to trust the provider's email address), with a **Check provider** action. A field the server's environment sets is shown read-only and says so; the client secret is write-only (the page is told only whether one is set). See [security.md §4](security.md#4-authentication).
- **Defaults** — about the *content a new assessment starts with*: the default currency, and the **scales**. Each scale (Standard and Small organisation built in, then any the admin creates) is a card with its three band editors — the **same** editor the assessment gets ([§8](#8-dashboard-assessment-list-and-creation)) — and **one Save for the whole scale**. A custom scale can be renamed and deleted (the confirmation says assessments keep their own copy); a built-in one can be edited but not renamed or deleted. **Create scale** takes a name and which scale to start from.

  **Defaults, not global settings.** Saving a scale first gives every assessment still pointing at its shared rows its own copy of them as they were (`SystemScaleCalibrator::detachFollowers()`), and only then edits the shared rows. A change therefore reaches assessments created from the scale afterwards, and assessments that choose it again in their settings, and nothing else.
- **AI assistant** — the assistant's switches (below). On a deployment without an inference runtime the tab shows one line, "AI service not installed", with a tooltip pointing to the README's *AI assistant* section; installation instructions are for whoever holds the compose file, not for this page.
- **Security log** — the stored security log ([schema.md §3.10a](schema.md), [security.md §11](security.md#11-logging-and-error-handling)), with a rate-limited download.

**The assistant's four switches.** The assistant is available only when the deployment has a runtime, an admin has switched the assistant on, the runtime answers, and — per feature — the feature's own switch is on: *Answer questions on the help pages*, *Suggest risks, attacks and defences*, *Draft the report's executive summary*. Help costs a corpus read, a suggestion about 30 seconds of CPU and a drafted summary a few minutes, so an admin may want the cheap one and not the expensive one.

Every switch can only ever **subtract**. No value stored here makes a feature work on a deployment with no inference container: `Availability` requires all four together and is the only thing any call site asks, and `UpdateSettingsRequest` rejects each feature flag as `declined` where `config('assistant.enabled')` is false rather than storing a `true` every read path would have to second-guess. The three feature switches render nested inside the assistant panel and are disabled while the assistant itself is off; like the assistant toggle they do not render on a deployment with no runtime. They default **on**, unlike the instance switch `assistant_enabled`, which defaults off: the instance switch is the decision, and defaulting the feature switches off would make enabling the assistant a two-step act whose second step is invisible. See [docs/ASSISTANT.md](../ASSISTANT.md).

**Profile** `/profile` — display name, email (changing it requires the current password), change password (requiring the current one), two-factor authentication (`TwoFactorPanel`: enrol, confirm, regenerate recovery codes, disable), active sessions with revoke, and delete account (confirmed with the password, naming how many owned assessments will be deleted along with it; an administrator can transfer them first). The colour theme is chosen from the sidebar.

**Sharing dialog** — opened from the editor's and report's action bar (`manage`): search existing users and add one with a role (viewer, commenter, editor, manager), change or revoke a role, transfer ownership (owner or admin), toggle the public flag, create and revoke the read-only share link (with copy), and see each collaborator's role and inviter. Admins also see **Publish as template** ([security.md §3.1a](security.md)). Template assessments have no Share button.

---

## 11. Accessibility

This tool is used in teaching, and some users rely on assistive technology.

- Every action is reachable by keyboard, with visible focus rings throughout.
- The tree is an ARIA `tree`: each node is a `treeitem` with `aria-level` and, when it has children, `aria-expanded`. A node that appears in several places carries a badge button (`aria-pressed`, `aria-description` "Appears in N places") because the hover-highlight affordance is unavailable to keyboard and screen-reader users.
- Live regions (`role="status"`, `aria-live="polite"`) announce save state, search result counts and similar status changes.
- Colour is never the sole carrier of meaning: status chips, banners and badges also carry text or an icon.
- Contrast meets WCAG AA in both themes.
- `prefers-reduced-motion` is honoured globally: animation and transition durations collapse to near zero.
- The modal traps focus, closes on Escape and restores focus to the triggering element on close.
- The dashboard tabs follow the ARIA tabs pattern (see [§8](#8-dashboard-assessment-list-and-creation)).
