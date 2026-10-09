# UI Specification

> Implements Phases 3, 5, 7 and 8 of [`docs/PLAN.md`](../PLAN.md). Work packages **W8**, **W10**, **W12–W15**, **W17–W21**.
>
> Reads numbers from [calculation-engine.md](calculation-engine.md) and entities from [schema.md](schema.md).

---

## 1. Principles

The legacy editor's core failure was **indirection**: to change anything you clicked a wrench icon to reveal action buttons, then navigated to a separate full page containing a form, saved, and came back. Every principle below exists to eliminate a step from that loop.

1. **Never navigate to edit.** Editing happens inline or in a modal over the tree. The tree never unmounts.
2. **The keyboard is the primary interface.** A facilitator typing while people talk in a workshop cannot reach for a mouse. Every frequent action has a key.
3. **Density over decoration.** The legacy node card wasted most of the viewport. Target 3–4× more nodes visible at once.
4. **The numbers are always on screen.** Quantification is the headline feature; it must not be hidden behind a modal.
5. **State what is unknown.** A missing value is shown as missing, never as zero and never as a blank that reads as zero.

---

## 2. Design system (W10)

Everything visual downstream consumes this package. Build it before the editor.

### Tokens

Tailwind 4 theme tokens, defined once in `resources/css/app.css`:

- **Node type colours** — root, risk, attack, defence, transfer. Each with a background, border and text variant that meets WCAG AA against both light and dark surfaces.
- **Status colours** — implemented (settled/green family), todo (planned/amber family), shelved (muted/grey family).
- **Magnitude ramp** — a 5-step sequential ramp for "how much does this contribute", used on contribution bars and chips. Sequential, not diverging; the quantity has no meaningful midpoint.
- **Surface, border, text** tokens for light and dark mode. Support both via `prefers-color-scheme` plus an explicit user toggle persisted on the profile.

**Colour must never be the only channel.** Status also carries an icon; magnitude also carries a number. Roughly 8% of male users have a colour vision deficiency, and this tool is used in teaching.

### Node type registry

Port `config/nodes.php` — the legacy per-type icon, label and child-type metadata is distilled product knowledge — to a single typed constant `resources/js/constants/nodeTypes.ts`:

```ts
export const NODE_TYPES = {
  root:     { label: 'Target of Assessment', childType: 'risk',    icon: …, color: … },
  risk:     { label: 'Risk',                 childType: 'attack',  icon: …, color: … },
  attack:   { label: 'Attack',               childType: 'defence', icon: …, color: … },
  defence:  { label: 'Defence',              childType: null,      icon: …, color: … },
  transfer: { label: 'Transfer',             childType: null,      icon: …, color: … },
} as const
```

`transfer` is a presentation variant of a `defence` with `is_transfer = 1`, exactly as in the legacy config. It is **not** a database type.

### Components

`resources/js/Components/UI/` — Button, IconButton, Chip, Badge, Modal, Dropdown, Tooltip, Toast, Tabs, Input, Select, Combobox, Toggle, Slider, Avatar, AvatarStack, Skeleton, EmptyState, ConfirmDialog, Bar (proportional fill), Money, Percent.

Icons: a single free, self-hosted set (Lucide). No icon font, no CDN, no external requests — see [security.md §6](security.md#6-content-security-policy).

### Navigation — a left sidebar (W41, replacing the W10 top bar)

**The top bar is gone.** Its only real navigation was a product mark and a
user menu. The rest was an assessment switcher and a global search box whose
placeholder read _"Search this assessment…"_ on every page in the application
— including the ones with no assessment, and wired to nothing on the page that
had one. The editor's working search lives in the toolbar (§3) and stays there.

```
┌────────────────────┐
│ ▣ ArgueSecure      │  logo → dashboard
│ ‹ Collapse         │  the width toggle, in its own row at both widths
├────────────────────┤
│ ⌂ Dashboard        │
│ ▾ My assessments   │  disclosure; the label links to the full list
│   ↳ Payment plat…  │
│   + New assessment │  opens the creation dialog (W73, item 19)
│ ▸ Shared with me   │  label → /assessments/shared; each row names the owner
│ ▸ Templates        │
├────────────────────┤  (bottom)
│ ? Help             │  → help.index (W51's pages, unreached until W82)
│ ⚑ Notifications  ③ │
│ ◐ Theme            │  light / dark / system
│ ⚙ Admin            │  only when the user is an admin
│ ◔ Profile          │
│ ⏻ Log out          │
└────────────────────┘
```

**History and Assessment settings are not here (W82, D41).** They used to be,
gated on the sidebar's own `currentAssessment` prop — which was the tell that
they never belonged: this sidebar is application-scoped (dashboard,
assessments, templates, admin, profile), and both of those are meaningless
without one specific assessment already open. They now live in
`Toolbar/AssessmentActions.vue`, rendered from `Components/Chrome/*` above
whichever view is on screen, so a facilitator finds them beside the
assessment they act on rather than one level above it. Removed from the
sidebar in the same commit that added them to the action bar (W36's rule: an
affordance cannot leave one surface in one commit and arrive on another in
the next — W72 broke that once and integration closed it by hand). Mounted
in `Pages/Assessment/Show.vue` for the chart and list (W82); the report gets
it when W83 turns that page into the third view of the same chrome — see
§9's "the chrome above the report" for the state of that page today.

**Help was built in W51 and reachable only by typing the URL until W82.**
Placed immediately above Notifications: it is the last thing reached for
before "how do I..." becomes "what just happened". It reads as current on
`/help` and on every `/help/{slug}` page.

**The help pages render inside this sidebar for someone signed in (2.5.1).**
They used to render their own header for everyone, with a "Back to the app"
link that always went to the dashboard — the only way out of help without
the browser's back button. `Components/Help/HelpShell.vue` now chooses the
chrome on `auth.user`, exactly as the report does (§9, D39): the sidebar for
a signed-in reader, and for a guest — help stays outside `auth`, for workshop
guests and share-link readers — the standalone header with a **Log in** link,
because `AuthenticatedLayout` has no user to render for them. The "Back to the
app" link is gone. The pages themselves are `resources/markdown/help/*.md`,
listed by `App\Domain\Help\HelpLibrary`, and are also the assistant's help
corpus in full (docs/ASSISTANT.md, job A).

**The rail shows a visible tooltip, not only an accessible name (W82, item
17).** `NavItem` already moved a compact item's label to `aria-label` and to
the native `title` attribute; a compact item now also wraps itself in the
shared `Tooltip` component (`Components/UI/Tooltip.vue` — the same one every
other hint in the product uses, not a second one built for this), so hovering
or focusing a rail icon shows the label on screen rather than only to a
screen reader and whatever the browser's native tooltip chrome happens to
look like. It wraps only when compact: the expanded panel already shows the
label as text, and tool-tipping a control that is not missing anything would
be noise.

**Two widths, from one persisted preference plus one contextual override.**
Docked open by default at 240px. Inside an assessment it is pinned to a 48px
icon rail, and expanding it **floats a panel over the content** rather than
beside it. The editor is a canvas: the chart lays out to the width it is
given, and a nav that reflowed it would rearrange the picture someone is
pointing at mid-workshop. The reserved gutter therefore depends on the rail
state alone and never on whether the panel is open — that single fact is what
the "expanding the nav does not change the chart's width" test asserts.

Leaving the assessment restores whatever the user had chosen. The override
never overwrites the preference, or the rail would follow them onto every
document page afterwards.

**The control that changes the width has one location (W73, item 18).** A
dedicated full-width row directly below the product mark, at *both* widths.

W41 gave the rail's control that dedicated row for a reason that is still
right — two 16px targets side by side in a 48px column is a coin toss, and
this is the control in the sidebar that gets used most — but put the expanded
one at `ml-auto` in the logo row. The consequence was that the button which
changes the width moved when the width changed: the click that collapses the
panel and the click that expands it again were never in the same place. The
fix keeps the dedicated row and moves the logo row's control down into it;
merging the collapsed one upward instead would have thrown away W41's
reasoning to fix W41's consequence.

It is labelled by visible text when there is room ("Collapse navigation") and
by `aria-label` when there is not, which is the rule every `NavItem` follows,
so the row cannot drift from the destinations under it. The unit spec asserts
its **row index** among the nav's own children — identical at both widths —
because "a toggle exists somewhere" was true before the fix and is precisely
the defect; the e2e spec asserts the consequence, that its vertical centre
does not move when the panel opens.

**"New assessment" opens the creation dialog (W73, item 19).** It links to
`assessments.index` with `?new=1`, and it lives *inside* the My assessments
group, below the list it adds to. Through W72 it was a bare `href` at
`assessments.index` — a control named for an action that navigated to a list,
where the user then had to find the real create affordance themselves, which
made the most-used verb in the product a link to somewhere else.

W73's first answer was a `POST` to a dedicated `assessments.new` route that
created an untitled assessment outright, on the argument that
`assessments.store` requires a title and a 240px column has nowhere to type
one. **That route was removed at 2.4 integration**, because the argument
overlooked where the control lands: the assessment list already carries a
button reading "New assessment" which opens a dialog asking for a title, so
the page ended up with two controls of the same accessible name doing two
different things — one prompting, one not. Same name with the same behaviour
is ordinary duplication; same name with *different* behaviour is ambiguous on
screen and indistinguishable to a screen reader, which announces both as "New
assessment, button".

So the sidebar opens the dialog the list page already owns. The 240px column
never had to hold a form — it only had to lead to one. Creation therefore has
a single flow and a single write path, `assessments.store`, and the title
requirement is uniform rather than depending on which of two identically named
controls the user reached for.

In the rail the group collapses to a bare link and has no list to sit under,
so the create control returns to the top level rather than disappearing with
the titles. The two renderings are mutually exclusive, so it is one control in
one place at any width.

**Accessibility is the substance here, not a footnote.**

- A `<nav aria-label="Main">` landmark, with a skip-to-content link before it
  in DOM order targeting a focusable `<main id="main-content" tabindex="-1">`.
- The rail's expand control is a real `<button>` carrying `aria-expanded`.
- Groups are disclosures: a `<button>` with `aria-expanded` and
  `aria-controls`, pointing at the list it controls.
- **In the rail, labels move to `aria-label`; they never simply disappear.**
  A notification count that cannot be drawn goes into the accessible name
  ("Notifications, 3 unread") rather than being reduced to a silent dot.
- Destinations first, account controls last, in DOM order, so tabbing walks
  the sidebar exactly as it reads.
- Nothing is inside a menu that could be a link. A dropdown is a click to
  reveal a click, which is the legacy defect this rewrite exists to remove,
  and it applies to chrome as much as to node cards.

**Notifications opens a panel, not a page (W47).** It is the one sidebar item
with no `href` — it renders a `<button>` through the same `NavItem`, so its
styling, its accessible name and its rail dot cannot drift from the
destinations around it. Through W46 it pointed at the dashboard, which was the
closest true thing at the time and still a link that did not do what its label
said.

The feed carries two sources in one list: **you were mentioned in a comment**
and **a node was assigned to you**. Every row links to the node through the
modal's `?node=` deep link — a notification that cannot take you to the thing
it is about is a badge saying "something happened somewhere". A row whose node
has since been deleted renders as text rather than as a link to a 404.

**Opened is read.** The badge counts unread only, and opening the list marks
everything read; the rows stay visible, so a per-row click would be a chore
that buys nothing. The count is a shared Inertia prop rather than a fetch the
sidebar makes on mount — the same query either way, minus a round trip and a
frame of the badge showing the wrong number — and the panel loads its feed on
open, never on mount, because it lives in the layout and would otherwise fetch
on every page in the application.

**In-app only. No email**, which needs a mail configuration, a digest policy
and an unsubscribe path.

**Hiding the Admin item is courtesy, not authorization.**
`EnsureUserIsAdmin` 403s the route regardless of what the client renders, and
the two are asserted in separate suites so that deleting one cannot look like
it still covers the other.

### Page chrome is one set of components, not a shape two pages both aim at (W81)

`resources/js/Components/Chrome/` holds the geometry above a page's content, and it is the **only** place that geometry is written down:

| Component | What it owns |
|---|---|
| `ChromeHeaderBand.vue` | The bordered band around a title row — `border-b`, the page surface, `px-4 py-4 sm:px-6`. |
| `ChromeTitleRow.vue` | The title itself and the actions beside it, at a stated `min-h-7`. |
| `ChromeToolbarBand.vue` | The toolbar band and the row inside it — **both** paddings — plus the right-hand control group and the switcher's anchor slot. |

`AuthenticatedLayout` renders the header band around its own `header` slot, so every authenticated page gets it as before and the layout's public interface is unchanged. **The report renders the same three components directly**, which is the point: it cannot be wrapped in `AuthenticatedLayout` — a share-link guest has no `{name, email}` user for it to render — and through W80 it therefore carried a hand-made copy of the shape. The copy drifted before it shipped (§9). The account chrome, the sidebar and the notification feed stay in the layout; only the geometry is shared, and nothing in `Components/Chrome/` knows about a user, an assessment or a store.

**`min-h-7` on the title row is stated, not inherited.** It is the height of a `size="sm"` Button, which is what both pages put beside the title. Taken from whichever control happened to be tallest, the band's height — and with it the vertical coordinate of the view switcher below — would change when a page hid one of its actions.

**One action bar, `Toolbar/AssessmentActions.vue` (W82, D40).** Settings ·
History · Share · Export · Print, always in that order, rendered into a
page's `ChromeTitleRow` slot rather than composed as four different
arrangements per page — which is what existed through W81: Settings and
History in the sidebar, Share as a bare `Button` in the editor's title row,
Export and Print as an `ExportMenu` and a second `Button` in the report's.
Four places that had to agree by inspection is the same failure this file's
own W81 section already names for the toolbar band's paddings. It takes the
assessment, the caller's `abilities` and the current `viewMode`, and renders
nothing a viewer cannot do: Settings and Share require `manage`; History,
Export and Print need only the `view` that got the bar mounted at all.
Share stays a page-level dialog — it emits `share` rather than opening one
itself — and Print calls `window.print()` after emitting `print`, so a page
can still layer something on top (the report's one-time "Choose Save as
PDF" hint) without this component knowing that hint exists.

Mounted in `Pages/Assessment/Show.vue` as of W82, for both the chart and the
list — the old bare Share button and the sidebar's History/Settings items are
gone in the same commit, per W36's rule. The report keeps its own
`ExportMenu` + `Button` arrangement (below) until W83 turns that page into
the third view of this chrome and mounts the same component there.

**`ChromeHeaderBand` hides itself from print (W82, D42).** Through W81 the
band sat outside `AuthenticatedLayout`'s `.editor-print`-scoped `<main>`, so
none of `print.css`'s editor rules reached it — printing the chart or list
printed the title row's actions right along with the picture. The report
never showed the bug because it independently wraps its own copy of the band
in a page-level `no-print` div. The fix is on the band itself, `no-print`,
which covers both callers without either having to remember it: the
editor's own running header (`AuthenticatedLayout`'s `.print-header`, inside
`<main>`) already carries the assessment title and the print date, so
nothing is lost by hiding the band's on-screen title along with its actions.

### Theme

Three states — **light / dark / system** — as a `radiogroup` at the bottom of
the sidebar, collapsing to a single cycling button in the rail so `system`
stays reachable at either width.

`system` is a real choice and the default, not the absence of one: it is the
only value that keeps up when the machine switches at sunset, and a binary
switch silently pins the theme the first time it is touched with no way back.
It is expressed by removing both classes, because `app.css` already scopes its
`prefers-color-scheme` block to `:root:not(.light):not(.dark)`.

Applied **before the first paint**, in two places that must agree:
`useTheme.ts` reads storage during setup (not `onMounted`, which is after
Vue's first paint), and a nonce'd inline script in `resources/views/app.blade.php`
covers the gap before any module runs. Both key off `arguesecure.theme`.

---

## 3. The tree editor (W12)

Route `/assessments/{uuid}`. This is the screen the tool lives or dies by.

**Two views over one store (W32).** The **chart** (§3.7) is the default: the assessment as an org chart, four tiers, built for a room. The **indented list** (§3.2) is the reading surface and what reports are built from. One store, one editor view, one modal — switching never reloads and never loses collapse state, filters, focus or the selected baseline. The choice is persisted per user per assessment.

### 3.1 Rendering a DAG, not a tree

A node may appear in several places — an attack under two risks, a defence under three attacks. The renderer therefore keys on **(node uuid, path)**, not on node uuid alone. The store holds nodes once; the view projects them into every position they occupy.

Expansion, focus and selection state belong to the **occurrence**, not the node: collapsing one occurrence of a shared defence must not collapse the others.

### 3.2 Node card

Compact by default. One line when collapsed, three at most when expanded.

**The list column is capped at a reading measure (W70).** The chart is a canvas and takes the whole window (W34); the list is not, and full-bleed on a wide monitor it put a title at the far left and its chips a screen-width away at the far right, with nothing between them — the eye had to cross the monitor to pair a value with the thing it belongs to, which is this view's one job. The cap is `max-w-4xl` on the `<ul role="tree">`, measured against a long risk title at 100% zoom. It is a cap and not a width: narrow windows are unaffected, the list stays left-aligned because an indented tree reads from its left edge, and because it sits on the zoomed element the measure grows with the type when someone zooms in. **Print is unaffected** — `print.css` resets zoom and fixes the type at 9pt (W33), and the cap is wider than an A4 text column, so it never binds on paper.

**Line 1 — always visible:**
`[expand] [type icon] Title [value chips] [indicator icons] [actions]`

**The list shows what a human entered (W36).** Of the three views, the list is the one for _filling in the values_, so the card carries inputs — impact, likelihood, status, strength — and no engine output. Everything computed is in the modal's Statistics tab and the report, where it appears against all three baselines at once. 2.0 rendered current and target side by side and unlabelled: a risk row carried three bare numbers, a defence five, and nothing said which of them a person typed and which the engine computed. W29 answered that by showing one baseline at a time; 2.2 answers it by not showing engine output on the card at all, which is why the baseline selector could go.

**Every figure is labelled, and every label, chip and bar explains itself on hover and on focus.** The copy lives in one module, `resources/js/constants/valueHints.ts`, because it is product knowledge rather than markup, and because the chart view renders the same ideas in a different shape — two views must not explain one figure differently.

**Value chips** by type:

| Type | Chips |
|---|---|
| root | `Base: €X`, `Current: €Y`, `Target ALE: €Z` |
| risk | `Impact: €X` (currency or level label) |
| attack | `Likelihood: 0.25/yr` |
| defence | status pill, `Reduces likelihood: 40%` |

- **The root is the exception, and carries all three totals.** It is the figure a facilitator glances at while working, and the three together _are_ the comparison the removed selector kept asking people to make one at a time. They are labelled **Base / Current / Target ALE** — "base" rather than "inherent", because the card is a workshop surface and the report keeps the formal term; `ROOT_TOTALS_HINT` is the sentence that tells a reader the two words are one idea. The chips are a polite live region: a collaborator's edit moves them without this reader touching anything. They replace the docked totals panel, deleted in W36 — two surfaces printing the same three numbers, one of them costing a permanent strip above the tree.
- The attack's **residual likelihood** is gone from the hover text as well as from the card. Residual is baseline-dependent, and with no selector, naming one baseline's residual would be an arbitrary choice stated as a fact. The hint keeps the entered rate and its `≈ p%`, both of which are what the user typed.
- **Every entered value on the row is a `ValueChip`, and only entered values are (W70).** One component, one label-colon-value shape, one hover/focus explanation apiece: impact on a risk, likelihood on an attack, the edge's strength on a defence, and the root's three totals. A reader who has learnt to read one row has learnt to read all four.
- Strength and reduction target stay **one chip, one fact**, and W70 gives them the shape above rather than a sentence: the **reduction target is the label** and the **strength is the value**, so `Reduces likelihood: 40%`. As two bare chips (`40%` beside `Reduces likelihood`) the reader had to infer that the number belonged to the words; the label-and-colon does that joining explicitly. An unset reduction target reads `Reduces overall loss` — the documented default, **not** a missing value, and never a `?`, which on this row means a missing strength and would otherwise be one mark meaning two things.
- Strength is read from the **edge this occurrence sits on**, never from the defence: it lives on the `attack_defence` pivot, so one control shows 80% under one attack and 20% under another, and both are correct.
- **The root carries no completeness bar (W70).** A bar states a fraction of a whole, and the whole — how many risk/attack pairs the assessment has — was never on the row and could not be inferred from it, so the fill was a shape without a scale. The figure is unchanged and still reachable: the modal's Statistics tab and the report state it as a count of pairs with the counts beside it. `Bar.vue` stays for those surfaces.
- The **"not applied in this baseline" dimming is gone** (W29 introduced it). It answered *is this control counting in what I am looking at?*, and the card no longer shows a figure it could be counted into. The status pill states implemented / todo / shelved outright, which is the whole of what the row knows.

A missing value renders as a **grey `?` chip**, never as `0` and never as blank. The hover text distinguishes the two kinds, because they behave differently: a missing impact or likelihood **excludes the pair from the total**, while a missing strength is treated as **no reduction at all**, which overstates residual risk — the safe direction.

**Indicator icons** — has comments (with unresolved count), has references, appears in N places, locked by another user, has missing values. All are buttons: clicking jumps to the relevant modal tab.

**Actions — always visible**, small and low-contrast until row hover or focus, then full contrast: **add child**, **add from template** (2.5.1, §3.3), **suggest** (only where the assistant is available, §7), **delete**. The details gear went in 2.5.1 — clicking the title opens the modal (§3.4). **No wrench, no reveal animation.** On touch, actions are always full contrast.

The title is renamed by **clicking it** (W29). The separate pencil action is gone: three routes into one edit — pencil, click, modal — is two too many, and a title that looks clickable and does nothing is the legacy wrench problem in miniature. The link action is gone for the same reason; the details icon opens the same modal, one tab away.

Nodes whose type has no `childType` (defence, transfer) do not render an add-child button, matching the legacy `config/nodes.php` semantics.

### 3.3 Adding a node — the single biggest speed win

Pressing add (the card's **+**, or `Enter` / `Tab` on a focused row) opens the node modal on a **draft**: the type it will be and the parent it will go under, with the title field focused. Type the name, press `Enter` (or **Add**), and the node is created — once, with its title — and the modal switches to it, so its values can be filled in next. `Escape` or Cancel abandons the draft.

- **Nothing is written until the draft is confirmed (2.5.1).** Through 2.5 the button inserted an empty node at once "so collaborators see it appear"; that is what put an untitled card behind the modal, told every collaborator "someone added a node" before it had a name, and — via the author's own `node.created` echo replacing the record the open tab was bound to — blanked the Details tab a second later. Abandoning a draft now leaves nothing to clean up.
- The draft carries a title and description, and for a defence its status and transfer flag. Everything else is set on the node's own tabs once it exists.

#### Suggestions: link, don't duplicate (W40)

While that title field is being typed into, it offers **attacks or defences
that already exist in this assessment**, of the type being added. Choosing one
**links** it — one node, a second parent — instead of creating a same-titled
twin. This is the same outcome as W38's _Link it here as well_, offered at the
moment someone is deciding rather than only when they drag.

Without it, an assessment ends up with _Phishing_ three times under three
risks: three nodes, three sets of defences to keep in step, and a total that
counts one attack as though it were three separate problems.

The rules that keep it from getting in the way:

- **The fast path must not get slower.** Nothing is highlighted until
  `ArrowDown`. Typing a new name and pressing `Enter` creates a node in
  exactly the keystrokes it always did.
- **It is advisory.** The list never takes focus, moves the caret, or rewrites
  what was typed. `Escape` closes it; a second `Escape` abandons the edit.
- **Only on insert, never on rename.** Renaming an existing attack to match
  another is not a request to merge them.
- **Every row says where the node already appears** ("already under 2 risks"),
  because linking to something not on screen is the part that needs
  explaining.
- **Nodes already under this parent are not offered**, so no choice can write
  a duplicate edge.

Suggestions come from `GET /assessments/{uuid}/nodes/search`, gated on
`update` rather than `view`: it feeds an edit, and "read" here would be an
inventory of every node title handed out one keystroke at a time to anyone
holding a share link.

**It lives in the draft form (2.5.1)**, the same in the list and the chart: the rows appear under the draft's Title field, for attacks and defences, and choosing one links the existing node under this parent and closes the modal. Nothing was created, so there is nothing to delete. W79's placeholder rules — a token marking the just-created node, an unconditional delete of it on accept — went with the placeholder.

The assistant's **AI suggestion** (W88 item 23) is offered on the draft too, before anything is typed, beside **From template** — see §7's "Three offers on an empty title". Either fills the form; the person still presses Add (D24).

#### Adding from a template (2.5.1)

Templates are not only for starting an assessment. The same library answers "what could go under this node?" from vetted content, in two places:

- **On the card** — an **Add from template** action (copy icon, labelled *Add attacks from a template* and so on) beside Add child, on both the list and the chart card, gated like Add child on `update` and on the node having a child type. It opens `TemplatePicker.vue` in `multi` mode: first the templates, each with its count of nodes of the child type (a template with none is disabled), then that template's nodes of the child type, filtered as you type on title and description, each with the parents it sits under in the template. One action per item, as many as wanted; the panel stays open until **Done** — the same rhythm as the assistant's five suggestions, and the composable (`useTemplateChildren.ts`) mirrors `useAssistantChildren.ts` for that reason.
- **In the draft form** — **From template** opens the same picker in `single` mode: **Use** on one item fills Title and Description (and a defence's transfer flag), closes the picker, and shows a note naming the template. Nothing is saved; the person edits and presses Add.

What is copied is the node's **words, never its values** — `title`, `description`, `is_transfer` — through the ordinary `addChild` write. A template's impacts, likelihoods and strengths describe its example system, and the standards templates hold placeholders; a figure copied across would look like somebody's judgement (`app/Domain/Templates/TemplateNodes.php`).

**Link, don't duplicate applies here too (W40).** In `multi` mode an item whose title — trimmed, case-insensitive — matches an existing node of the child type is offered as **Link**, which links that node under this parent instead of copying it; if it is already under this parent it reads *Already here* and does nothing. Risks cannot be linked (one parent, the root), so an existing risk is always *Already here*. In the draft form the filled title runs the ordinary §3.3 search, so the same match is offered as a link there.

The picker sits inside the card, so on both cards the card **stops being a drag source while it is open** — a press-and-move on its text would otherwise pick the card up — and in the chart a click inside it neither selects the card nor reaches the double-click handler.

Endpoints: `GET /templates/options` (every usable template with node counts per type) and `GET /templates/{uuid}/nodes?type=risk|attack|defence`, both behind `AssessmentPolicy::useAsTemplate` — see security.md §3.1a.

### 3.4 Editing a node

**The list and the chart edit a node the same way (2.5.1).** A single click on a node's title or description opens the node modal on **Details**; a click on an entered value (impact, likelihood, strength) opens it on the tab that holds that value; every such area shows a pointer cursor. `F2` or `Space` on a focused row opens it too. The list's inline title field and its gear icon are gone — two editors for one field is how they diverge.

The one inline edit left is the **description in the list**: double-click it to edit in place (`Enter` commits, `Escape` reverts). A single click there waits out the double-click window before opening the modal, so a double-click never opens the modal on top of the field.

**One editor per node.** While the modal is open on a node it holds a whole-node lock (`node`, see [§6](#6-real-time-w16)); anyone else opening that node gets it read-only with "Currently being edited by …" in the header, cards show a lock with the holder's name, and `NodeController::update` refuses their writes (423). When the holder closes the modal, the other person's becomes editable in place. The inline description edit takes the same lock.

### 3.5 Autosave and conflict handling

An explicit indicator in the toolbar: **Saving… / Saved / Conflict**.

Every node PATCH carries the node's `updated_at`. On mismatch the server returns **409** with the current server state, and the client shows a small inline diff — _"Dana changed this while you were editing"_ — with **Keep mine** / **Take theirs** / **Cancel**. Never silently clobber, and never silently discard the user's typing.

Locks are cooperative UX; the version check is the correctness guarantee. Both exist because a lock can expire mid-edit.

### 3.6 Undo (W14)

`Ctrl/Cmd+Z`. A single linear per-assessment stack over structural changes only — create, delete, move, link, unlink, clone, catalogue insert. Semantics are specified in [schema.md §3.10](schema.md#310-assessment_operations--the-undo-stack).

The prompt names the author when undoing someone else's action: _"Undo Dana's deletion of 'Phishing'?"_ If the inverse cannot be applied cleanly, show an explicit refusal — never a partial apply.

Field edits are **not** on the undo stack; they are recovered from the node's History tab, which is a normal edit.

---

### 3.7 Chart view (W32) — the default

The assessment as an **org chart**: Target of Assessment on top, then risks, attacks and defences, one tier per row, siblings side by side, connectors drawn downward. At fit-to-width it scrolls **horizontally only** — the schema fixes the depth at four, so the vertical axis is bounded and the reader manages one dimension instead of two. Collapsing a risk is how a wide assessment is made to fit; a **fit to width** button does the rest.

**Zoomed in, it scrolls both ways (W34, PLAN-2.2.md D9).** Horizontal-only held while the chart fitted the viewport, and stopped holding the moment someone zoomed in to read a dense branch — at which point refusing to scroll made the bottom tier unreachable and zoom itself pointless. The chart view also occupies the **full window width**: it is a canvas, not a document, and the centred 1280px column letterboxed it on exactly the wide screens it exists to exploit.

This is the default view because it is what the product is _for_. The list is better for reading; the chart is better for thinking, and ArgueSecure's claim is that a room of people can build a threat model together in an afternoon.

**Layout is a pure function** — `composables/useTreeLayout.ts`, occurrences in, coordinates out, no DOM and no store. Bottom-up width accumulation: a leaf occupies one card, a parent occupies its children plus gaps or its own card, whichever is greater, and centres over that span; a collapsed occurrence is a leaf. A layout bug that can only be reproduced from a screenshot is a layout bug nobody fixes.

**Card width is per tier (W69, PLAN-2.4.md D27).** A base unit times a factor: defence 1, attack 1.25, risk 1.5, root 2. The tiers do not carry comparable amounts of text — the root holds the assessment's title and the whole-graph bar, a defence holds a control's name — so one width spent the chart's scarcest resource evenly on unequal needs. The algorithm does not change shape; only the constant becomes a lookup, and connector geometry reads box coordinates, so it is unaffected.

That does interact with the horizontal-scroll promise, and the interaction is bounded: widening the upper tiers widens `layout.width` — which is what **fit to width** divides the viewport by — **only where a tier's own card is wider than its children's span**. On a wide assessment the leaves set the width and the upper tiers fit inside it for free; on a shallow one (a root over a single risk, or an empty assessment) the widest single card sets it and the chart really is wider. Both cases are pinned. Nothing about it touches the height, which is the thing that would start vertical scrolling.

**The `ul`/`li` tree survives.** Only the card inside each `<li>` is absolutely positioned. Flattening the chart into four rows of divs would be simpler and would cost the screen-reader reading of the assessment, `aria-level`, focus mode and the keyboard outline — all of which key on the occurrence path and work unchanged while the nesting stands.

**A shared node is drawn once per occurrence**, not once with several incoming edges. That keeps this a tiered tree rather than a general DAG-layout problem, and duplicate highlighting already exists to make the repeats legible.

**What a card shows (W37, W69):** type, title (two clamped lines), the description (three clamped lines), and — in the bottom row — **the node's own value**. Clicking it opens the node modal on the tab that fixes it: Values for a risk or an attack, Strength for a defence, because strength lives on the `attack_defence` edge and so is per occurrence. Defences also carry a status **icon only** (implemented / todo / shelved), with the word in the tooltip and the accessible name, and the value sits after that glyph. Plus the "appears in N places" badge and an unresolved-comment dot.

W69 moved that indicator out of the title row, where it competed for width with the two-line clamp, and made it render the **figure** rather than a checkmark (PLAN-2.4.md D26). A checkmark answers "is this filled in", which is a question about the data-entry task; the figure answers "what did we say", which is what a room asks of a board.

| Type | Set | Unset |
|---|---|---|
| Risk | `€400k` — impact, abbreviated | `?`, in the missing-value styling |
| Attack | `0.4/yr` — ARO | `?` |
| Defence | status glyph, then `60%` — strength **on this edge** | glyph, then `?` |
| ToS | a two-segment bar: current and target loss as a fraction of inherent | nothing — the root has no own values |

Three things that are easy to get wrong here, each with a test:

- **The defence figure is per occurrence.** Strength lives on the pivot, so the same defence under two attacks shows two different percentages. That is correct, and it is why the card takes the edge as a prop rather than looking one up from the uuid.
- **A qualitative value is not a number.** Such a node carries a `*_level_id` and no amount; the level's short code is what gets rendered. `ScaleMapper` is the only thing that turns a level into a figure, and it does that for the engine, against the assessment's own calibration — never for a card.
- **The space is reserved in both states**, which is why W37 made the indicator permanent in the first place. A card must not reflow when a value is filled in.

The **ToS bar is the one computed figure on the board**, and the ToS is the one node it belongs to: it is the whole assessment's answer rather than any one person's input. Two segments — current and target — against a full-width inherent ground, with the three totals in the tooltip. A bar and not a number precisely so it is not mistaken for one of the typed-in figures above it, and with no `?` state, because unlike the other three tiers there is nothing here anyone could go and fill in.

**No cues, and no computed figures.** W32 kept a completeness bar, a contribution bar, a likelihood ramp and a strength ramp on the argument that a cue is not a figure. They were figures with the digits filed off: a reader had to hover each one to learn anything, and four visual languages across four tiers made the board harder to read across — the one thing this view exists to make easy. W37 deleted all four and W69 brought none of them back: what W69 added is the value **the node was given**, not a value derived from it. A card still shows no annual loss, no contribution and no residual, because those are answers the list, the modal's Statistics tab and the report give — and the ToS bar is the single, deliberate exception, on the single node that means the whole graph.

The indicator is present in **both** states, always. A conditional one would reflow the card when a value was filled in, and would put the control that navigates to the values on screen only while the values were missing.

The **root has no indicator**: it owns no values and has no values tab, and a control that leads nowhere is worse than a gap.

**No tier gutter (W37).** It cost 84px of horizontal space permanently, to name four tiers the schema fixes and that never change. The tier is still announced — every card's accessible name begins with its type (_"Risk: Customer data breach"_) and `aria-level` still gives the depth. A deliberate trade of a sighted redundancy for width, not a free deletion.

**The card height is a budget.** Two title lines plus three description lines at 1080p is a promise about a sum: viewport ≈ 937px, minus ≈ 193px of page chrome, leaves ≈ 744px for four tiers at 124px plus three 44px gaps = 628px. The arithmetic is in `useTreeLayout.ts` and pinned by a test. **If the toolbar ever grows a row, the fix is the toolbar, not the description clamp** — which is why W34 came before W37.

**Interactions:** a click on the title or the description opens the node modal on Details, and a click on the value indicator opens the tab that fixes it — the same gestures as the list (§3.4, 2.5.1); a click anywhere else on the card selects it (in a chart, clicking a card is how you point at it while talking), and `Enter` on the title opens the modal. Hover reveals a panel **at the bottom edge** with collapse and add child, and beside them **add from template** (2.5.1, §3.3) and, where the assistant is available, **suggest** (W85). Their panels float below the card rather than pushing the layout around. It moved from the top-right, where it covered the values indicator, and lost Open and Delete — opening is the double-click, and **Delete is in the modal header, on every tab**. A destructive action reachable only by hovering a two-pixel icon is the wrong thing to have in a view used on a projector. Adding a child opens the modal on a draft, exactly as in the list (§3.3); nothing exists until it is named and added. The draft's Title field is also where §3.3's **link-don't-duplicate suggestions** appear, which is the only keyboard route from a single-parent attack to a second link once D31 moved the popover control onto the multi-occurrence counter.

**Re-parenting by drag (W38).** Cards are draggable in the chart as well as the list. Dropping a node on a different parent asks **Move or Link**, because the gesture is ambiguous and the two readings are different mutations, one of which destroys an edge (PLAN-2.2.md D11):

| Choice                   | Effect                                                                                                                       |
| ------------------------ | ---------------------------------------------------------------------------------------------------------------------------- |
| **Move it here**         | The source edge is deleted and the target edge created. One node, one parent.                                                |
| **Link it here as well** | The target edge is created and the source kept. One node, two parents — the duplicate badge then says "appears in 2 places". |

Both go on the undo stack, with a summary naming the verb and the destination (_"Linked 'Phishing' to 'Fraud'"_), because undoing the wrong one of these is otherwise indistinguishable. **Creating a link was not undoable at all before W38** — W13's `LinkController` never called `UndoService` — which was tolerable while linking was a form field and is not once it is a drag.

Three guards, each with a test:

- **Link is withheld when the edge already exists.** Writing it hits the unique index and surfaces as a 500. The dialog says why the option is missing rather than omitting it silently.
- **Cross-type drags are refused at `dragover`**, not at drop. Previously the cursor accepted an attack onto a defence and then did nothing — an affordance that says yes and means no.
- **Position is appended, never inherited.** Ordering lives on the edge, so a node arriving under a new parent has no position there.

In the chart the drop target is the **parent card itself** — there is no sibling to aim at across tiers. In the list it is a sibling, which also fixes the order. **Reordering within a parent stays list-only.**

---

## 4. Keyboard map (W14)

Document all of it on a `?` overlay, and make that overlay the first thing the help page links to.

| Key                               | Action                                                |
| --------------------------------- | ----------------------------------------------------- |
| `↑` `↓`                           | Move focus between visible nodes                      |
| `←` `→`                           | Collapse / expand, or move to parent / first child    |
| `Enter`                           | Add sibling below (on a node); commit (in a field)    |
| `Tab`                             | Add child                                             |
| `Shift+Tab`                       | Promote node one level                                |
| `F2`                              | Edit title inline                                     |
| `Space`                           | Open the node modal                                   |
| `Escape`                          | Cancel edit, close modal, or clear focus mode         |
| `Delete`                          | Delete focused node (with confirm if it has children) |
| `Ctrl/Cmd+Z` / `Shift+Ctrl/Cmd+Z` | Undo / redo                                           |
| `/`                               | Focus search                                          |
| `Ctrl/Cmd+K`                      | Command palette                                       |
| `Ctrl/Cmd + +` `−` `0`            | Zoom in / out / reset                                 |
| `?`                               | Keyboard help overlay                                 |

`Tab` is deliberately overloaded away from browser focus traversal **only while a tree node has focus**. Restore native `Tab` everywhere else, including inside the modal, or the editor becomes unusable with a screen reader.

---

## 5. Canvas, search and focus (W15)

- **Zoom** — buttons, `Ctrl+scroll`, and `Ctrl +/−/0`. Range 50%–200%, persisted **per view, per assessment, per user (W84, PLAN-2.5.md D43).** Before D43 the two views shared one stored number, so setting the chart to 70% to see the whole graph also set the list to 70% — two different jobs at two different sizes, forced to agree. Each view now has its own persistence key and its own baseline (below), and changing one leaves the other exactly where it was. **A value stored under the pre-D43 key (no view component) seeds both views once**, on whichever is opened first after the upgrade, and only then do the two diverge — so a zoom set before this shipped still means something, for both views, rather than being silently discarded. **The two views spend the number itself differently too (W34):** the chart scales geometrically as a CSS transform, and the list scales with CSS `zoom`, which enlarges the type and leaves the container's width alone so rows reflow inside it. A transform on the list pushed it past the viewport when zoomed in and away from the right edge when zoomed out; a `font-size` on the list would not move a single card, because every card size is a Tailwind `rem` utility resolved against the root element. The control labels itself for the view it is in — "text size" or "chart size" — because the same two buttons now do two different things.
  **What 100% means moved, and the number did not (W69, PLAN-2.4.md D28; made per-view by W84, D51).** Both views rendered too small at 100%, so an exported baseline — `CANVAS_BASE_SCALE`, now `{ chart: 1.1, list: 1.35 }` rather than one shared number — multiplies into the chart's transform and the list's `zoom`. 100% now paints what 110% (chart) or 135% (list) used to. Raising the *default* instead was rejected, for the chart originally and for the list again at D51: it makes the control read something other than 100% on a first visit, which is the complaint itself. The stored number, its persistence key, the 50%–200% range, the step and the label are all untouched, so every saved preference keeps exactly the meaning it had when it was written. Anything converting layout units to painted pixels — fit to width, and the sizer the chart's scroll container reads — multiplies by the matching view's baseline too. **The list's baseline is higher than the chart's on purpose (D51):** it is the reading surface and opens more zoomed in than the chart, which wants to show the whole shape of the assessment first. This is not a substitute for the row spacing and indent below, or vice versa — zoom scales the gaps along with the text, so it cannot fix density, and denser spacing does not change how large either view opens.
- **Row spacing and indent (W84, item 16, D51).** The list's rows sit `mb-1.5` (6px) apart, and each nesting level indents by `ml-4`/`pl-4` (16px, up from 10px) — both grown specifically because the list read as too dense at the old values, and neither is achievable by raising the baseline above, which scales gaps and text together rather than opening rows out.
- **Centring (W84, items 11/12).** The list always centres in its (capped) viewport — `mx-auto` on the `max-w-4xl` column, so on any window wider than the cap there is equal space on both sides rather than the column sitting hard against the left edge. The chart centres **only when it is narrower than its scroll container**, and left-aligns once it is wider: `mx-auto` on a block element with an explicit painted width does exactly this in CSS (the auto margin computes to `0` the moment the content cannot fit), so no conditional logic is needed. A chart that centred unconditionally would push its root off-screen to the left the moment it grew past the viewport — the one thing this must never do.
- **Scrolling on both axes**, with panning by middle-drag or space-drag. Deep trees are wide; the legacy layout only scrolled vertically.
- **Sticky ancestor breadcrumb** when scrolled past a parent.
- **Search** (`/`) — full text over title and description, backed by the `FULLTEXT` index. Results highlight in place and the canvas scrolls to each match; `Enter` and `Shift+Enter` step through them.
- **Filters** — by tag, defence status, node type, and **Missing values**. Non-matching nodes dim rather than disappear, so structure stays legible. The _available_ filters sit behind one **Filters** trigger carrying a count of what is on; the _active_ ones stay on the bar as removable chips with a one-click clear, so what is filtered is still readable without opening anything (W34 — sixteen chips inline wrapped the bar by themselves, and the second row is vertical space the chart view needs). "Missing values" dropped its "only": the filter dims what does not match rather than hiding it, so "only" described a mode that never existed. For a defence, the missing value is the **strength on that occurrence's edge** — a control rated against one attack and not another is missing a value in exactly one of the two places it appears.
- **Focus mode** — isolate one subtree. Everything else is hidden, a breadcrumb shows the path back, `Escape` exits.
- **Collapse/expand** per occurrence plus expand-all and collapse-all. Persist per user per assessment.
- **Duplicate highlighting** — hovering or focusing a node that occupies more than one position highlights **every** occurrence with a distinct outline and draws a connector between them. An explicit "appears in N places" chip does the same on click, and is the accessible equivalent of the hover behaviour.
  **The counter names the places (W69, item 11).** It said "Appears in 3 places" and would not say which, which is a statement a reader can do nothing with. Its tooltip now lists the parent titles — the risks an attack sits under, the attacks a defence sits under — one per line, capped at eight with "and N more" after that. The cap is where a list stops reading as a list; someone whose attack sits under fifteen risks is learning that the number is large, not hunting for one of them. The same names go on the trigger's accessible name, because the bubble is `pointer-events-none` decoration a screen reader never reaches. This required a `#content` slot on `Tooltip` — a `text` prop cannot carry line breaks, since the bubble is one inline element and newlines in it collapse to spaces. **Read-only in W69; W72 builds the interactive popover that can navigate to each occurrence (PLAN-2.4.md D31).** Shipped on the chart card; the list card is outstanding.
- **View switcher (W32)** — chart or list, in the toolbar, persisted per user per assessment, defaulting to the chart. Both views read the same store and editor view, so no state is lost across a switch.
- **Baseline selector — removed (W34, PLAN-2.2.md D8).** W29 introduced it so that no two cards could show different baselines. 2.2 removes the disagreement at its source instead: after W36 no card in either view shows a baseline-dependent figure, so the selector drove nothing a user could see, and a control that changes nothing is worse than no control. The baseline vocabulary survives where the comparison belongs — the node modal's Statistics tab and the report, both of which show all three side by side rather than asking anyone to choose one.
- **Totals panel — removed (W36).** The three totals moved onto the ToS card as Base / Current / Target ALE ([§3.2](#32-node-card)), which is the node they describe. A docked strip printing the same three numbers cost a permanent row of vertical space above the tree, and two surfaces rendering one figure is the disagreement this plan keeps removing.
- **Toolbar layout (W34)** — one row, in order: search · filters · (spacer) · view switcher · zoom. It must not wrap at 1280px. This is a budget, not a preference: toolbar height is height the chart view does not have for its two title lines and three description lines at 1080p.

---

## 6. Real-time (W16)

- **Presence avatars** in the top bar, and a coloured ring on the node each collaborator currently has focused.
- **Locked fields** show an inline _"Locked by Dana"_ badge and become read-only. **Never a modal overlay** — the legacy full-node `.node-lock` overlay blocked reading as well as writing.
- **Granular broadcasts** carry the changed data so the client patches its store directly. Do not repeat the legacy pattern of broadcasting an id and then re-fetching a rendered HTML fragment per change.
- **Toasts** are throttled and grouped: _"Dana made 6 changes"_, not six toasts. Suppress toasts for one's own actions.

---

## 7. Node modal (W13)

Opened by `Space`, the edit action, or clicking an indicator icon.

### Tabs, by type (W39)

| Type    | Tabs                                                                                         |
| ------- | -------------------------------------------------------------------------------------------- |
| root    | Details · **Scales** · Statistics · References · Assignees · Comments · History              |
| risk    | Details · **Impact** · Statistics · References · Assignees · Comments · History              |
| attack  | Details · **Likelihood** · Statistics · References · Assignees · Comments · History          |
| defence | Details · **Cost** · **Strength** · Statistics · References · Assignees · Comments · History |

**Scales renders only for someone who may `manage`** (W72). That is the ability
`UpdateAssessmentSettingsRequest` is gated on, so hiding the tab from a
collaborator who may edit nodes but not own the assessment is courtesy; the
FormRequest is the guard and refuses on its own.

| Tab                            | Contents                                                                                                                                                                                                                                                                                                                                                            |
| ------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Details**                    | Title, description, **labels** (W43). For a defence, also **status** and the **transfer flag** — moved off the Cost tab by W72, because neither is a cost.                                                                                                                                                                                                          |
| **Scales**                     | The assessment's own settings, on the node that _is_ the assessment (W72) — see below.                                                                                                                                                                                                                                                                              |
| **Impact / Likelihood / Cost** | The one thing this type is quantified by — see below. One panel per type, as before; only the label is per type, so a reader knows what the tab holds without opening it.                                                                                                                                                                                           |
| **Strength**                   | Per-edge strength, reduction target and rationale for the attacks this defence applies to. **Edited from either end of the `attack_defence` edge (W31)** — one row, one editor — though after W39 only the defence's end has a tab. A defence that could only display a read-only percentage and point the reader at the attack was the legacy indirection rebuilt. |
| **Statistics**                 | Read-only. Every figure the engine computed for this node, across all three baselines side by side. See below.                                                                                                                                                                                                                                                      |
| **References**                 | CVE and friends (W21)                                                                                                                                                                                                                                                                                                                                               |
| **Assignees**                  | Who is responsible (W46). Multi-select over the assessment's collaborators. Assigning grants nothing — it puts the node on their dashboard with what it still needs. Someone who is not on the assessment cannot be picked; the tab says so and points at the Share dialog.                                                                                         |
| **Comments**                   | Threaded, resolvable (W18)                                                                                                                                                                                                                                                                                                                                          |
| **History**                    | Revision list with per-field revert (W18)                                                                                                                                                                                                                                                                                                                           |

**Tab keys are not tab labels.** The URL carries `?node=…&tab=…`, and those
keys are `details`, `values`, `links`, `statistics`, `references`, `assignees`, `comments`,
`history`, `scales` — unchanged by W39's renames, because a shared `?tab=links`
must keep working. `scales` is W72's, and it is new rather than a rename: the
root had no settings tab at all before, only a page at another URL. A deep link
naming a tab the node's type does not have falls back to Details rather than
rendering an empty modal — which now includes `?tab=scales` followed by someone
who may not manage the assessment.

### Labels (W43, item 18)

A chip row on Details with a type-ahead over the labels this assessment
already uses. **Typing a name that does not exist creates it** — there is no
separate screen for managing the vocabulary, because asking a facilitator
mid-workshop to go and define "PCI" elsewhere first ends with the label not
being applied at all.

- **Duplicates resolve by slug, on the server.** "PCI DSS", "pci dss" and
  "PCI DSS" are one label; the first spelling wins the display name and is
  what the response carries, so two people never see one label spelled two
  ways. The client does not second-guess this locally — two implementations
  of one rule is how they drift.
- **Assessment-scoped**, per schema.md §3.11. The same name in two
  assessments is two rows.
- **Chips render in the modal only.** Not on the chart or the list cards,
  which W36 and W37 deliberately cleared of everything that is not an input.
  The exception is the **root node**, whose labels are the assessment's own
  and appear on its row in the assessment list (§8).
- **Removing a label leaves it in the vocabulary.** It may be on twenty other
  nodes, and even when it is not, offering it again is how a mistaken removal
  costs one keystroke rather than a retype into a near-duplicate.
- **No colour on the chip.** `tags.color` exists in the schema and is not
  read: the card's colours already mean node type, defence status and missing
  value (§2), and a user-chosen colour beside them competes with a vocabulary
  the reader has learned.
- Labelling requires `update`, like every other node field — a label is an
  edit everyone sees, not a personal bookmark, and `is_public` grants read
  only.

The **filter bar** (§5) has offered a tag filter since W15 with nothing to
filter; W43 is what supplies the data. The filter itself is unchanged.

### Three offers on an empty title, and they are not the same offer (W88 item 23; 2.5.1)

A draft (§3.3) opens with an empty title, and **three** different offers sit
under that one field. They must not be conflated, in the interface or in the
code:

|             | **Link, don't duplicate** (W40/W79)                                       | **From template** (2.5.1)                        | **AI suggestion** (W88; one since 2.5.1)        |
| ----------- | ------------------------------------------------------------------------- | ------------------------------------------------ | ----------------------------------------------- |
| Source      | the assessment's own nodes, searched                                      | a template's nodes of the child type, picked     | the local model, asked for **one**              |
| Chosen item | links the draft's parent to the existing node; nothing is created         | fills the draft's title and description (and a defence's transfer flag) | fills the draft's title and description |
| Trigger     | typing                                                                    | a button, pressed                                | a button, pressed                               |
| Named       | `suggest*`, `SuggestionList`                                              | `template*`, `TemplatePicker`                    | `assistant*`, never `suggest*`                  |

**The two buttons are offered only while the title is empty, and only to
someone with `update`** (2.5.1). Once the person types, the question has been
answered by the person who asked it, and a button that would replace their
words is in the way; the buttons, and an open template picker, come back if
the title is cleared. Neither writes anything: both fill the form and show a
note saying where the words came from, and the node exists only when the
person presses Add.

**The AI suggestion asks for one, and fills the form directly** (2.5.1). Until
then it asked for five and showed a list to accept one from; the form makes one
node, the count is most of the wait, and the server now scales the token
budget to the count (`Suggester::budgetFor()`, docs/ASSISTANT.md §5). With one
item, a list to pick it from was a click with nothing to decide. **A suggestion
that arrives after the person has started typing is discarded**, and an empty
answer says so rather than leaving the form silently unchanged.

**The button is pressed, never automatic.** A suggestion costs CPU seconds, so
generating one for every draft anybody opens would spend them on every node
whose title the user already knows.

**It is offered only on a draft (2.5.1)** — never on an existing node, so a
node reached by a pasted `?node=…` offers nothing.

**It is absent, not disabled, where the assistant is unavailable** — a control
that cannot work is worse than no control, and a disabled one still says the
feature exists. From template is always offered to an editor; with no
templates, the picker says so.

**Filling the form is not accepting it** (PLAN-2.3-ai.md D24). The accept step
is the Add button, pressed by a person who can see the text and the note that
a model wrote it — through the same validated write path a typed title takes.
Only `title` and `description` are read from a suggestion (D23), and it renders
as plain text: it sits in an `<input>` and a `<textarea>`, and this codebase
has no HTML renderer for generated output.

### Saving the title and description (W88, items 21 and 22)

The Details fields autosave, and the title commits on every keystroke. Two
rules make that safe, and both were written after reproducing the failure they
prevent (`tests/js/stores/nodeWriteOverlap.spec.ts`):

- **One write in flight per node.** A keystroke typed while a request is out
  does not start a second request; it merges into a single follow-up that goes
  when the first response lands. Unserialised, the second request carried the
  `updated_at` the first request had already superseded, and the server
  answered 409 — so the "Someone changed this while you were editing" banner
  fired at a user who was simply typing quickly. Writes to _different_ nodes
  are independent and are not serialised against each other.
- **A response never overwrites what has been typed since it was sent.** The
  server's row is applied to the store, and the keystrokes accumulated since
  are re-applied on top of it. Without that, an echo one round trip old wrote
  itself into the field the user was still typing in: type `Ph`, and a moment
  later the field reads `P`.

**Neither of these narrows §3.5.** A genuine second editor still moves the
server's row under a single write, that write still comes back 409, and the
banner still offers Keep mine / Take theirs. A fix that suppressed conflicts
would have satisfied both rules above and broken the feature they sit inside,
which is why the spec file asserts the second editor's case alongside them.

**Structure is edited by dragging, not by a tab.** W38 made a cross-parent drop
ask _Move or Link_, which is how an edge is created or re-parented. The two
things dragging cannot do — detaching one of several parents, and reaching a
parent that is scrolled away or filtered out — live behind the **parent
counter** for the `risk_attack` edge and on Strength for `attack_defence`.
Detaching the _last_ parent is refused with a reason: it would hide the node
rather than delete it.

### The parent counter's popover (W72, D31)

The `shuffle` badge on a card says how many places a node appears in. Pressing
it opens **Appears under**: the risks this attack belongs to, a detach action
per risk, and a type-ahead that links it under another. It is the same content
that sat permanently expanded on the Details tab until W72; D31 moves it rather
than deleting it, because it is **the only place in the application that can
detach an attack from one of several risks** — no card carries an unlink
action, and dragging moves an edge rather than removing one.

- **Detach is offered only when there is more than one parent.** With one, the
  panel says why instead: removing it would leave the attack with nowhere to
  show, so delete it or link it elsewhere first. An orphan is a state the
  engine reports on and the tree cannot render.
- **Reading is not editing.** Someone without `update` still sees where the
  node appears; the detach buttons and the combobox are absent, not disabled.
- **The panel is anchored, not nested.** Every surface the counter sits on —
  the modal body, the chart canvas, the tree scroller — clips its children, so
  the panel is rendered outside them and positioned from the trigger's
  measured rect, exactly as the combobox inside it is (D29). "Click outside to
  close" therefore has to name the trigger, the panel _and_ the combobox's own
  listbox, since none of the three contains the others.

The modal is **non-blocking over the tree** — the tree stays visible and the modal is dismissible with `Escape`. It never becomes a route; deep-linking to a node uses a query parameter so back/forward behave.

### The shell (W71)

**The header carries the type as an icon, left of the title** — the type's own
icon in the type's own colour, with the type name as both its accessible name
and its tooltip. It was a neutral chip in the right-hand actions group until
W71, which put a piece of _identity_ in the corner reserved for _verbs_, at the
far end of the header from the title it described. **Delete stays in that
actions group**, where W37 put it and for the reason W37 put it there.

**Panels are lazy, and stay mounted once opened.** A panel is created the first
time its tab is selected and hidden rather than destroyed afterwards. Both
halves are load-bearing:

- Four panels fetch on mount — History, Comments, References, Statistics — so
  mounting all eight up front cost four requests every time anyone opened any
  node, and a throw in any one of them during render took the whole tab
  subtree down with it. Details, the panel actually being looked at, was the
  visible casualty; "the Details tab does not load" is what that looked like
  from the outside.
- Destroying a panel on every tab switch would lose an unsent comment draft
  and refetch a history that had not changed.

**A modal open on a node the store does not hold says which of two things
happened**, and never renders an empty body — empty is indistinguishable from
a panel that failed. _Loading_ when the store has not been hydrated yet, which
is a `?node=` deep link arriving early; _gone_ when the store is populated and
this uuid is not in it, which is a node deleted since the link was shared.

**The tab strip wraps; it does not scroll** (PLAN-2.4.md D30). A horizontal
scroller hides tabs behind an edge with no affordance saying so, and a defence
has eight of them. The single line under the strip is the strip container's own
bottom border, so it stays one edge however many rows the buttons take; each
tab's thicker underline is the selection marker and sits on its own row. Arrow
keys move between tabs in **strip order**, not visual row order, with exactly
one tab tabbable at a time — so wrapping changes nothing about reaching the
second row from the keyboard.

**Type-ahead popups escape the modal's scrolling body** (PLAN-2.4.md D29). The
comboboxes on Details ("Appears under") and Strength are the last element of
their section, and the modal body is a scroll container, which _clips_ an
absolutely-positioned child regardless of its stacking order. The listbox is
therefore rendered outside the modal and anchored to its input by measurement,
flipping above the input when there is no room below. Two consequences are
part of the contract rather than incidental: the input names its listbox and
its active option by id (`aria-controls`, `aria-activedescendant`), since
containment no longer expresses either; and "click outside to close" means
outside _both_ the input and the popup, since the popup is no longer inside
the input's own subtree.

### Value tab by type

**Risk — Impact.** A mode toggle (quantitative / qualitative). Quantitative shows a currency input; qualitative a level picker showing each band's label and range. **The field label must read "loss each time this occurs"**, with helper text distinguishing it from annual loss. This is the single most consequential input error available in the tool.

**Attack — Likelihood.** Mode toggle. Quantitative shows a rate input labelled **"how often per year"**, with a live hint: `0.5/yr ≈ 39% chance of at least one occurrence this year` computed as `p = 1 − e^(−ARO)`. Values above 1 are legitimate and must not be rejected.

**Defence — Cost.** Two fields, one-off and annual, with the annualised total
shown beneath. That is the whole tab (W72 item 9).

**Status and the transfer flag are on Details**, where the rest of what a node
_is_ lives: status as a three-way segmented control, transfer as a toggle that
changes the icon and label. Neither is a cost, and a tab named Cost that opened
on a segmented control answered a different question from the one its label
asks.

**Strength is not here at all**, in any form. It is per link, it is the
Strength tab's entire subject, and that tab is one click away on the same node.
Until W72 this tab carried a read-only summary — "80% against Phishing, 20%
against SQL injection" — beside a link saying to go and edit it elsewhere,
which is the legacy indirection in miniature: two places showing one number,
only one of them editable. The Statistics tab still repeats the per-edge list
beneath the figures it explains, because there it is one read-only table
explaining another.

### Scales tab (W72, item 10)

The root node _is_ the assessment, so the settings that say what its figures
mean belong on it: **currency**, **risk tolerance**, **amortisation period**,
and a read-only summary of the three qualitative scales with a link to the
calibration screen (§8, W65). None of this is new — W44 built all of it on
`/assessments/{uuid}/settings`, reachable only from the sidebar, while
everything else about the assessment is edited by opening its root node.

**One controller, one FormRequest, two screens.** The tab reads
`AssessmentSettingsController::edit` and writes its `update` through
`UpdateAssessmentSettingsRequest`. There is no second endpoint and no second
validator for these columns; the settings page is unchanged and still works.
Two consequences of reaching a page controller from a modal are part of the
contract:

- **The read is an Inertia request made by hand**, on first activation of the
  tab. `Assessment/Show` is not sent the currency list or the amortisation cap
  — only the settings controller has them — and putting a config array on
  every editor page load for a tab most sessions never open is the wrong
  trade.
- **The write does not follow the controller's redirect.** That redirect is
  right for the settings page and would replace the assessment with a settings
  screen from here. The store is patched locally instead, and a changed
  amortisation period triggers a recompute, because it is the one setting on
  this tab that moves a figure.

**Bands are still calibrated on their own screen.** The tab summarises and
links; §8's editor owns ordering, bounds and overlap.

### Statistics tab (W39)

Read-only, one table, three columns — **Inherent · Current · Target**, in that
order, always. This is where the baseline vocabulary lives now that no control
selects one: W36 and W37 took the computed figures off both cards precisely
because each card had to pick a single baseline, and the picker is gone.

| Type    | Rows                                                                                                                                                                                                                |
| ------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| root    | total risk; reduction against inherent in currency and percent; plus a sentence for the current→target gap no column can show, and a coverage note                                                                  |
| risk    | ALE; share of the assessment total; reduction against inherent                                                                                                                                                      |
| attack  | ALE; likelihood as entered; residual likelihood; reduction against inherent                                                                                                                                         |
| defence | _if switched off, loses_ (`MC`); _if done, would save_ (`MCadd`); share of reduction (`Attr`); net annual benefit; risk removed per unit spent (`CBR`) — plus a non-baseline cost block and the edges it applies to |

Rules the tab must not break:

- **Every figure comes from the engine's own output.** No arithmetic beyond
  subtracting one baseline's field from another's.
- **`MC` and `MCadd` are separate rows, never collapsed.** They answer
  different questions and at most one applies per column
  (calculation-engine.md §9.3). The node card's old single "Saves" chip
  collapsed them, which was right for a chip and wrong for a table.
- **Missing renders as the `?` chip, never as `0`.** Three cases carry real
  meaning: a shelved defence's `attribution`, an unpriced defence's `CBR`, and
  a share of a total that is itself zero.
- **The inherent column's reduction-against-itself is `—`, not `€0`**, which
  would read as "the defences achieved nothing".
- **Nothing here is editable**, and nothing here is a number a human typed.

### Reduction target — and the note that prevents a bug report

**Strength comes first, then the reduction target, then the rationale** (W72
item 9). The tab is named after strength, and the reduction target refines a
reduction that has to exist before it can be refined — it does not move the
assessment total at all. The order is asserted as a sequence, not as the
presence of the controls.

The per-link reduction-target selector offers **Reduces likelihood / Reduces impact / Reduces overall loss (default)**, with blank meaning the default. Beneath it, permanently:

> This choice affects the intermediate figures shown on the attack and risk — the residual likelihood and the retained impact — but not the assessment total, because all reductions multiply together.

---

## 8. Dashboard, assessment list and creation

### Dashboard (W42)

`/dashboard` is where Laravel sends people after login and where the sidebar's
home link points. Until W42 it rendered the assessment list — the same page at
a second URL, answering half the questions someone arriving actually has.

**Two sections of tabs, not four lists (W73 item 21, D32).** W42 stacked the
four below in that order. The order was right; the shape was not. The two that
answer *what needs me* ended up half a screen from the two that answer *where
do I go*, and neither pair fit above the fold — so the page that exists to be
read at a glance had to be scrolled to be read at all.

| Section | Tabs |
|---|---|
| **Notifications** | Assigned to me · Mentions |
| **Assessments** | Mine · Shared with me |

Work still comes first: Notifications is the first section and *Assigned to
me* the first tab in it, and each section opens on its first tab.

**The unresolved counts stay on the tab labels.** A count that is hidden until
you click the tab is a notification you will not see. The number is plain text
inside the tab button, so it is part of the tab's accessible name rather than
a coloured pill a screen reader walks past, and a `countLabel` says what it
counts when the label alone would not ("3 unresolved"). Mentions counts only
*unresolved* ones, because a thread someone has since closed is not waiting
for anybody. A zero draws nothing at all.

`DashboardTabs` owns the tablist; `DashboardSection` grows a `panel` variant
that drops its heading and landmark, because in a panel the tab *is* the
accessible label via `aria-labelledby` and an `<h2>` as well would announce
the same name twice. One component with two variants rather than a copy: the
empty states are prose, and prose is the first thing that drifts. Panels are
`v-show`, not `v-if` — a destroyed panel's empty state stops existing for
anything reading the page as a whole. The tablist is the first interactive
thing after the heading, so it carries the full APG contract: `aria-controls`
and `aria-labelledby` wired both ways, roving `tabindex`, and
arrow/Home/End with focus following selection.

The four lists themselves, in the order they are useful. **Work first,
inventory second:**

1. **Assigned to me** — nodes assigned to the user, with the assessment, the
   type, the title and _what the node still needs_ — an impact on a risk, a
   likelihood on an attack. Landed in W46. A defence never carries one:
   strength lives on the `attack_defence` edge, so it can be complete against
   one attack and blank against another, and a single word on a row cannot say
   which. Scoped to assessments the user can still reach, independently of the
   hook that clears assignments when a collaborator is removed — a dashboard
   is the wrong place to discover a stale grant.
2. **Mentions** — comments mentioning the user, unresolved first. Landed in
   W47. Read from the notifications already sent, not by re-scanning comment
   bodies: `MentionParser` resolves who was meant once, at write time, against
   the assessment's collaborators only, and a second implementation here would
   eventually disagree with it. `resolved` is read live, because it is the one
   field that changes after the mention — a thread since closed sorts down.
3. **My assessments** — owned, most recently updated first.
4. **Shared with me** — **with the owner's name on every row**, which is the
   thing that was missing.

Each panel is an excerpt (six rows) with "See all" to the full list. A
dashboard that paginates has stopped being a dashboard. *Mine* sees all to
`/assessments`; *Shared with me* sees all to `/assessments/shared` (D33,
below), which is the link that used to be a promise its destination could not
keep. The two Notifications tabs have no "see all", because there is no page
behind them.

**Empty-state first, and this is a requirement rather than a nicety.** A new
user sees all four panels empty on their first visit, and two of them stayed
empty until W46 and W47 landed; both now have.
Every empty state names what will appear there and why, in the product's own words — never referring to a work package, since
"Comments that mention you will show up here" is true today and stays true
afterwards. The page was built for four sections from the start: a layout
designed around the two that happen to have data, then retrofitted, is a worse
layout.

**D32 changed the shape and kept all four empty states verbatim**, which is
the part of W42 that was got right — each one is as true inside a tab panel as
it was under a heading.

The seam is `App\Domain\Dashboard\DashboardData`. `assignedToMe()` and
`mentions()` were the only places W46 and W47 needed to change, and both
documented the row shape they must produce so the page and the data could not
disagree. It held: W46 filled `assignedToMe()` and `Dashboard.vue` needed no
edit at all.

Scoping is the same rule as the list: owned and shared only. A public
assessment is reached by its link, never advertised on a stranger's arrival
page.

### Assessments shared with me (W73 item 20, D33)

Route `assessments.shared` — `GET /assessments/shared` — titled *Assessments
shared with me*, reachable from the sidebar's group header and from the
dashboard tab's "See all". Both of those pointed at `/assessments` until W73,
a list that mixes owned and shared rows and does not distinguish them, so the
one collection whose defining property is *whose it is* could only be reached
through a list that does not say.

It is a real collection with its own emptiness, and the emptiness is half the
argument for the page: "Nobody has shared anything with you" is a different
statement from "you have no assessments", and the combined list could only
ever make the second one — which reads to a new collaborator as if their work
had been lost.

**Every row names the owner**, display name only. An assessment list is a page
about other people's work, which is exactly where an address leaks. It reuses
`AssessmentRow` unchanged rather than growing a second row component. No
per-row actions: like the dashboard's rows this page is for deciding where to
go, and Share/Duplicate/Delete each render from a server-computed permission
and live on `/assessments` where that machinery already is. Unpaginated, like
the list — a collection sized by how many people have invited you does not
need pages.

**Two guards, and the order between them is the point.** The query is scoped
by `assessment_user` membership *first*, which is what decides whether a row
exists here at all; `AssessmentPolicy::view()` then re-checks every surviving
row, because CLAUDE.md requires every route touching an assessment to go
through the policy and a collection route that only ever scoped its query has
no executable claim to that.

The policy pass **cannot run alone**. `is_public` grants read to whoever holds
the link (security.md §3.1), and so does being a template — both are true
statements about opening an assessment and neither is the statement
"advertise it on a stranger's page". Running the policy second means it can
only *remove* rows, never add one, so it cannot reintroduce the leak it would
be on its own. That is asserted directly: neutering the membership clause
turns the public-assessment and template tests red while the private-stranger
test stays green, which is the demonstration that the policy is the weaker of
the two filters here.

Both live in `DashboardData`, not the controller — the ordering is load-bearing
enough that a controller is the wrong place to be able to get it the wrong way
round — and `sharedWithMe()` (the tab's six) and `sharedWithMePage()` (the
whole list) share one query builder, so the two surfaces cannot come to
disagree about what "shared with me" means.

### List

- **Rows** — title, description, owner (on shared rows), badges for
  public / template, and the **root node's labels** (W43, item 18). Only the
  root's: labels on any other node stay inside the editor, or a row would
  carry a hundred chips.
- **Filter** (W42, item 13; labels added in W43) — a search field that narrows
  the rows already on screen, client-side, matching title, description and
  label case-insensitively. **No
  request.** The controller sends the list unpaginated, so round-tripping to
  hide part of it would be slower and no more correct. This is the search the
  deleted top bar was pretending to offer (§2). A plain substring, not fuzzy: a
  list of a dozen does not need ranking, and a fuzzy match that surfaces the
  wrong assessment is worse than no match when the next click opens someone's
  risk register. A filter matching nothing says so — it must not fall back to
  the "create your first assessment" empty state, which reads as if theirs had
  been lost.
- **Per-row actions** (W42, item 20; revised W86 item 7) — **Settings**
  (`manage`), **Share** (`manage`), **Export**, Duplicate (`view`), Delete
  (`delete`, with a confirm dialog naming the assessment). Report and
  Download are gone: Export opens the same `ExportMenu.vue` the editor's
  action bar opens (D40, `Components/Toolbar/AssessmentActions.vue`) — one
  export path, whichever screen it is opened from, not a second menu with
  the same name. The row and the action bar now offer the same verbs for the
  same object, minus History and Print, which have no meaning outside one
  assessment's own view.

**Every action renders from a permission the server computed, never from
ownership.** A manager who is not the owner can share; an admin who is neither
can delete. `is_owner` gets both wrong. Hiding a button is a courtesy; the
route is the guard, and both halves are asserted in separate tests.

**Duplicate is gated on `view`, not `manage`** — the clone touches nothing in
the source and lands in the actor's own ownership, exactly the reasoning
`templates.use` follows. It goes through `AssessmentCloner` unmodified, and
renames the copy to _"… (copy)"_: the cloner reproduces the root title
verbatim, which is right for a template and wrong here, where it would leave
two identical rows in the list and two more in the sidebar.

- **Create** — from blank, from a template, or by importing JSON. Choosing a template shows what it contains before committing.
- **Settings** (manager role) — currency, amortization years, scales, public flag, share link, template flag. **Not** title or description (W86, item 9) — see below.

### Assessment settings (W44, item 12; title/description removed W86 item 9)

`/assessments/{uuid}/settings`, gated on **`manage`** — not `update`. An
editor may change every value in the assessment and still not this: these
decide what those values _mean_. Reachable from the editor's action bar
(`Components/Toolbar/AssessmentActions.vue`, D40/D41), gated the same way (a
courtesy — the route refuses independently), and carries its own
**"Return to assessment"** link, top right, since this screen is otherwise a
dead end once you are on it.

**No title or description here.** They live on the **root node** and are
edited there, in the tree — this screen used to carry its own copies, which
is the two-editors-for-one-field mistake CLAUDE.md's history rules warn
about: the write went through the ordinary `NodeObserver`/`RevisionRecorder`
path on save, so the history page was recording a title/description edit
from a second place, on top of the tree's own.
`UpdateAssessmentSettingsRequest` `prohibited`s both fields outright, not
just drops the inputs — a removed input is not a removed endpoint.

| Setting             | Effect                                                                                                                                                                                                                                                                                                           |
| ------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Currency            | **Display only.** Symbol and number formatting. No conversion, no rates — the figures stay exactly as entered. Validated against a fixed list in `config/arguesecure.php`, never a free string: the value reaches `Intl.NumberFormat`, which throws on an unknown code and would blank every figure on the page. |
| Risk tolerance      | **Display only.** An annual-loss threshold. Nullable — "not set" and "zero" are different statements, and zero is a legitimate one.                                                                                                                                                                              |
| Amortisation period | The one setting here that **does** move a computed figure: `DefenceRanker` spreads capex over it, so every annual cost, CBR and ROSI changes. Bumps `calc_version` for that reason; the other three deliberately do not.                                                                                         |
| Scales              | **Edited here since W87** (D46), in three cards below the form — impact, likelihood and defence strength — each saving on its own request and each offering a reset to the instance default. The screen that used to hold them is deleted, route included. See "The scale cards" below.                     |

**Where the tolerance appears, and what it must never do.** A badge beside the
totals on the ToS card (`"€15,000 over tolerance"` / `"Within tolerance"`,
compared against **current** — the question is whether today is acceptable,
not whether a plan would be) and a dashed threshold line on the report's
baseline chart, which widens its own scale to keep the line on the chart.

It states a comparison and never a verdict. It does not recolour bars,
re-rank defences, or label anything high or low: `calculation-engine.md`
forbids band-mapping a computed figure, and a user-supplied threshold does not
license it. The display-only claim is pinned as an executable assertion in
`tests/Feature/Assessment/SettingsTest.php` — the totals are compared figure
for figure before and after — rather than left as a sentence here.

### The scale cards (W65; relocated and reduced in W87)

**Three cards at the foot of Assessment settings, not a screen of their own.** W65 built `/assessments/{uuid}/scales`; W87 moved the cards onto the settings page — where the rest of "what do these values mean" already lives — and **deleted that page and its GET route** (D46). A screen that still answers its URL after its entry points are removed is how a second, divergent editor survives a consolidation. The two write routes remain: `PUT /assessments/{uuid}/scales/{kind}` and `PUT /assessments/{uuid}/scales/{kind}/reset`.

Both are gated on **`manage`** — calculation-engine.md §8.4 pitfall 6 requires it by name: "editing a scale changes every number computed from it. Bump `calc_version` and require the manager role." A reset is gated identically, because it moves every figure just as thoroughly as an edit does.

Each card is a table of bands with **label, range and representative**, saved independently — impact is agreed with the business and likelihood with whoever watches the logs, and one Save spanning all three would make each of those a change to the other two.

**Two columns went, and neither is a loss.**

- **Code** (D44). `scale_levels.code` is internal: derived from the label when a band is created, then **frozen**, and shown to the user nowhere — including the exported methodology table, which printed it as a column. It is still in the payload the editor is handed, as an opaque round-trip token that the editor sends back untouched; that is what freezes it across a rename. An id cannot do this job, because a custom scale is cloned per assessment and its level ids do not exist until the clone runs, while a catalogue fixture is shipped once and must resolve in all of them. Re-deriving on rename would detach every catalogue entry naming the old value, silently. A band the editor creates carries `null` and is named server-side by `LevelCodeDeriver`, which resolves collisions against `(scale_id, code)` explicitly — two bands both called "Moderate" is an ordinary thing to do now that the label is the only field there is.
- **Colour** (D45). Nothing outside the calibrator and the old screen read `scale_levels.color`. The column stays with its `#9CA3AF` default; the input is gone.

**The invariants are made unreachable rather than validated.** A band's upper bound and the next band's lower bound are one number, held once and rendered twice, so invariant 2 cannot be broken by typing. Adding a band **splits** an existing one, so the outer bounds never move. Removing one gives its range to a neighbour, so `[0, ∞)` stays covered — a gap would make the reverse lookup partial, and a value falling in the hole reads as missing and silently leaves the total. Moving a boundary is clamped inside its neighbours and recomputes both representatives, because a representative left behind by a band moving around it is how invariant 5 breaks with nobody touching the field. `ScaleValidator` still checks all five server-side and is the authority; it should never fire from these cards, and if it does the defect is in the cards. Its messages name a band by **label**, not by code (W87) — the one sentence a user reads when a save fails must not point at a string the table no longer shows.

**Ordinals are parked before they are renumbered.** `(scale_id, ordinal)` is unique, so removing or reordering a band cannot renumber rows one at a time: the first write collides with a row that has not been deleted yet. `LevelOrdinals` pushes the whole existing range clear in one statement first. This was unreachable from W65's screen on a *first* save — that one clones, and a fresh scale has no rows to renumber — which is why it survived until the admin tab, which never clones, met it immediately.

**Clone on edit.** A system scale is shared by every assessment on the instance, so the first edit copies it into the assessment and repoints the foreign key. Editing in place would silently move every figure in every other assessment using it — the legacy global-tags defect with money attached. Stored values are re-pointed **by code** across the copy, because an unrepointed `scale_levels.id` belongs to a scale the assessment no longer loads and would read as missing. A band that is removed has no new row to point at: those values are cleared into the legal "qualitative, nothing chosen yet" state and **counted** into the message, because someone spent a workshop arriving at them.

**Reset to default**, one per card, applies the bands of the seeded system set that `instance_settings.default_scale_calibration` names — the same set a new assessment is created with and the same set the admin Defaults tab edits, so "default" means one thing everywhere. It goes through the ordinary calibrate path, so values are re-pointed by code, and it **does not** repoint the assessment back at the shared system scale: doing so would make a later admin edit silently move this assessment's figures, which is exactly what clone-on-edit exists to prevent.

**No live-preview calculator** at the foot of a card (W87). "A €50,000 loss falls in moderate, worth 31,623" answered a question the table of ranges above it already answers, and it was the only reason a colour reached the client at all. The rule it also carried — a value exactly on a boundary falls in the **higher** band — stays, as a sentence under the table.

**No "Still using the system defaults" warning** (W87). Using the defaults is the normal state; most assessments never calibrate, and a warning says something is wrong. What `is_system` actually decides is still stated, as a fact on the card: **Instance default** or **This assessment's own** — which is to say, whether a save will clone. The engine's `uncalibrated_scales` advisory is unchanged and still keys on the same flag.

### Reading a saved version (W63)

Route `/assessments/{uuid}/snapshots/{id}/view`, gated on `view` — the same ability as the snapshot list and the snapshot's figures page, because it renders the same decoded graph those already serve.

**It mounts `ChartView` and `TreeNode`, the live editor's own components**, against a store built from `snapshots.graph` by `SnapshotViewPresenter`. A second read-only renderer was the alternative and it drifts: a tier gains an indicator on one side only, and the version people open when they are worried is the one still drawing it the old way. The presenter therefore mirrors `AssessmentController::nodeToArray()` key for key, and a test compares the two key sets directly so drift fails rather than accumulates.

Read-only is asserted twice, because once is not enough:

- **Every ability is false**, which removes each add, delete and edit affordance. That is the half that gates what is _offered_.
- **The store is built `frozen`**, which makes every method that would reach the network a no-op. This is the half that matters. `loadUnresolvedCommentCounts()` fires from `onMounted` with no ability check at all, and the label ids on a frozen graph are minted for the render rather than being real rows — so a write escaping the first half would attach an unrelated live label to a node.

Four deliberate absences:

- **No node modal.** Its tabs read live comments, history and references; rendering today's conversation against a version it did not happen on is worse than not offering it. The cards hide the control rather than leading nowhere.
- **No comments, presence or locks.** They are live state and are not in a snapshot. Said once at the foot of the page, not as a badge on every card.
- **No Report position in the view switcher.** A report route serves the _live_ assessment, and reaching today's figures from a page about an old version is the one navigation this page must not make easy.
- **Nothing is recomputed.** `results` is served from the column exactly as stored.

The banner is sticky and says the version's name, date and author, because everything below it looks exactly like the assessment. Restore is offered here too, behind the same warning dialog the list uses — this is where someone is when they have decided.

Zoom, view mode and collapse state persist under a key namespaced by snapshot id. Namespacing them by the assessment's uuid would let reading an old version quietly rewrite the reader's state in the live editor.

---

## 9. Reports (W17)

Route `/assessments/{uuid}/report`, with an options step before generating: baseline (current / target / both), include or exclude shelved, include comments, include the methodology appendix, and **show assignees** (W46, default off).

**There is one way of showing figures, and it is not an option (W80).** The panel offered "Full figures" / "Qualitative only" from W17 to W79; it bound nothing in any renderer, was pinned as a defect by W74, and was removed rather than built. The reason is [calculation-engine.md §8.3](calculation-engine.md#83-which-scale-for-which-quantity), which fixes which displayed quantities have a scale at all — impact `Imp(r)`/`Imp′`, likelihood `ARO`/`ARO′`, and strength. Every ALE, and with it every cost, CBR, ROSI and net-benefit figure, is "none — rendered as currency", and §11.2 step 8 repeats it: *do not band-map ALEs*. So no setting could stop the report printing currency in every register row, every ranked list and every programme figure, and a control labelled "Qualitative only" above such a document says something false about it — the more so because the methodology appendix has printed "computed loss figures are shown as currency, never mapped to a low/medium/high band" to the same reader since W17.

The honest half of what the option gestured at **already happens, per node rather than per report**: a value renders as the level its author chose where `impact_mode` / `likelihood_mode` is `qualitative`, and as the amount they typed where it is `quantitative`. That is the same rule the chart and list cards follow for typed values (§3.2, and PLAN-2.4 item 23 — "the chips are the *typed* values, not computed ones; computed figures keep their existing treatment"), so a report of a qualitatively-elicited assessment already reads as levels without being asked. What a global switch would add is dishonest in both directions: forcing qualitative band-maps an amount its author elicited precisely, and forcing full prints a level's `representative` as currency — inventing a figure nobody gave, off a scale §8.4 pitfall 4 warns may not be calibrated at all.

The false-precision worry behind the option is real, and §8.4 pitfalls 3 and 4 already answer it — an order-of-magnitude range beside the figure, and a persistent advisory on an uncalibrated scale — rather than by withholding the numbers a report exists to deliver. A stale `figures=` URL is ignored, as any unknown parameter is.

Sections are specified in [`docs/PLAN.md`](../PLAN.md) Phase 7, plus **the plan: cost and benefit** (W33) — what the todo controls cost, annualised and broken into one-off and recurring, against the risk they remove in currency, and the return that ratio implies. Controls with no cost recorded are counted beside those totals and never folded into them: an unpriced control is not a free one. Charts are **inline SVG** — no canvas, no chart library that renders to bitmap — so they survive printing at full resolution.

**The full-assessment section is the list view on paper (W33)**: every node with the values a person entered _and_ the ones the engine computed, under inherent, current and target, down to each defence's strength on that link, its reduction target, its rationale and all three cost figures. A missing value prints as a dash.

**Control coverage (W102, PLAN-2.7 D65–D66)** follows Transfers when any defence cites a framework control (schema.md §3.12): per framework, in config order, each cited control in natural order with a status — *implemented* when any citing defence is, *planned* when none is but one is todo, *shelved* otherwise — and the defences citing it, each with its own status and a note when it counters no attack. It lists only what is cited and never says "compliant": the standards templates already cite every control, and a report is not a compliance certificate (PRODUCT.md). `include_shelved` binds it as it binds the shelved ranking: when it drops a control the section says how many, and a framework left with nothing to list is not drawn as an empty card. No citation, no section — in every format, not an empty heading.

**The report is the third view (W35, PLAN-2.2.md D10).** The editor toolbar's view switcher reads **Chart · List · Report**, which is the working order — sketch the shape, fill in the values, read the answer — and the report page renders the same switcher with Report selected, so the way back is the control that brought you. Each row of the assessment list also carries **Report** and **Download report**, for the reader who has not opened the assessment at all. The whole of the report shipped in 2.0 behind a URL nothing linked to; W33 over-corrected with three copies of the link in the editor chrome, and W35 keeps the reachability while removing the duplication.

**Report is a route, not a persisted view mode.** `useEditorViewMode` still stores only `chart | list`, and the third position is derived from the current route. Persisting it would mean a reader's detour became the view their editor opened in next time. Choosing Chart or List _from_ the report writes the persisted mode before navigating, so clicking List lands in the list rather than in whatever was last used — including for an anonymous share-link reader, who is the `anon` case the storage key already carries.

**The options row is always visible (W83).** It closed and reopened from W35 to W82 — a **Close** button dismissed it and a **Report options** button brought it back, persisted per user. W83 removed that state entirely: every option now sits on one row immediately beside the search box, always on screen, next to the other two views' own always-visible toolbars. Changing one still re-requests the page with a new query string exactly as before; nothing about that binding changed, only whether the controls can be hidden.

**The report is the third view, not a different page (W83).** Through W82 it had no sidebar at any point, no search, no zoom, and its options lived in a panel the other two views did not have. W83 closes that gap along the one axis it can: an authenticated viewer gets the same sidebar shell chart and list already have (`AuthenticatedLayout`, §2), the same reused `SearchBox.vue` (§5) searching the report's own content (titles, descriptions, rationale — never a computed figure), and its own per-view zoom (D43) scaling the document's type size, applied to the report body only — the chrome above it and the print output are both untouched.

**Two chromes, chosen on `auth()->user()` (D39).** The report's audience is wider than the sidebar can serve: a share-link guest has no `{name,email}` user for `AuthenticatedLayout` to render, so wrapping the page in it unconditionally would break every share link. An authenticated viewer — owner, collaborator, admin, or an authenticated reader of a public assessment — gets the sidebar; a guest gets the bare page the route has always rendered, with the same report content, search, options and zoom either way. The two branches mount the same `Components/Report/ReportDocument.vue` and `Components/Report/ReportOptionsRow.vue` once each, so the content and the search/options/zoom row cannot drift the way the toolbar geometry once did (below) — what differs between them is only the surrounding chrome.

**`AssessmentActions` is mounted for an authenticated viewer only (D40).** The bar bundles Settings, History, Share, Export and Print, and gates Settings and Share on the `manage` ability — but **History carries no ability gate anywhere it renders**, because in the editor and the assessment list "anyone who can see the bar" is always an authenticated viewer with at least `view`. That stops being true on the report: a guest reaching History's route would be bounced to a login page for an assessment they were never asked to sign in to see. Rather than adding a gate that component has never needed anywhere else, the report does not mount it for a guest at all — Export and Print stay reachable through the page's own controls instead, since neither of those routes carries `auth` middleware.

### The chrome above the report (W74, rebuilt in W81)

**The switcher occupies the same screen position in all three views**, so switching is a change of content and not a change of layout. It is the *same* chrome, not a matching one: both pages render `Components/Chrome/*` (§2), so the header band, the title row and the toolbar band have one definition each.

W74 could only reproduce the shape, because the report cannot be wrapped in `AuthenticatedLayout` — a share-link guest has no `{name, email}` user for it to render. **The reproduction drifted, and the drift shipped.** The editor has *two* paddings above its toolbar, one in `Pages/Assessment/Show.vue` and one in `Components/Toolbar/Toolbar.vue`; the copy carried only the outer one, so the report's switcher sat 6px high. Two numbers in two files that had to agree by inspection did not. That is what W81 removed — the numbers still have to agree, but there is now only one of each.

Both coordinates are now identical, and both are asserted in a real browser (`tests/e2e/10-report.spec.ts`) because a pixel claim in prose is not a guarantee — this one was wrong in prose too.

- **Vertically**, the switcher sits at the same y and at the same height in chart, list and report.
- **Horizontally**, at the same inset from the right edge. This was open through W80 and is the reason: the editor's row carried the zoom controls *after* the switcher, and the report had nothing to zoom, so the two differed by that group's width. W74 refused to close it with an empty spacer sized to the zoom group — rightly, since a spacer asserts the fix rather than the property, has to be resized by hand whenever the zoom group changes, and makes the test that measures it pass for the wrong reason. W83 closed the gap for real, by giving the report its own zoom rather than reserving space for the editor's.

**The switcher is last, and that is the mechanism.** `ChromeToolbarBand` puts it in an `anchor` slot after a `trailing` slot: both pages now fill `trailing` with their own zoom controls (`Components/Toolbar/ZoomControls.vue` in the editor, `Components/Report/ReportZoomControls.vue` — chart/list-size wording does not fit a page with neither — on the report), and neither reserves space for the other. The switcher's distance from the window's right edge is the band's own padding and nothing else, for any siblings either page adds later. Zoom and the switcher remain adjacent inside the one right-hand group, which is what W34 put them together for — zoom is a property of the view you are in, and after W34 it does two different things depending on which one that is; W83 makes it a third.

The left edges are still not comparable, and are not claimed to be: the editor's band is inset by the sidebar gutter and the report's is not.

**The row is two groups, not one pile, beside the search box (W74, made permanently visible by W83).** Radio groups sit inline — **baseline** and **shelved**, the latter a choice between two accounts of the same programme ("Implemented & planned" / "Include shelved") rather than an inclusion. The remaining toggles — comments, methodology, assignees — each add or remove a section and group together beside them. Stated as one flow-wrapping list of controls of a single kind, the row would make it impossible to see that choosing Target excludes Current while including comments excludes nothing. `include_shelved` is still one boolean on the wire; every existing URL keeps working.

There were three radio groups until W80 removed **figures** from between the other two. The distinction the grouping states — renderings in one group, inclusions in another — was never about the count, and two state it as well as three did.

### Export: one menu, five formats (W74, D34)

Every export is one **Export** menu: **HTML**, **CSV**, **JSON**, **Excel** and **Word**. Built on `phpoffice/phpspreadsheet` and `phpoffice/phpword`, both LGPL, both self-hosted, no service. **PDF is not among them and is not missing** — it is the browser print pipeline below (D6), which is why `dompdf` stays gone and no headless-Chromium sidecar joins the deployment. Faking `.xlsx` with a CSV and `.docx` with HTML produces files that open with a repair dialog, which is worse than not offering the format.

| Format | What it is |
|---|---|
| HTML | One self-contained file — inline CSS, no script, no external asset. The CSP rule binds a file that leaves the server as hard as a page served by it: this one is opened from a mail attachment on a machine that has never met the deployment. |
| CSV | Every section, not the ranked defence list it was through W73. The report is risks, attacks, defences **and edges**; strength lives on the `attack_defence` pivot, so a defence-only file cannot say that one control is 80% effective against one attack and 20% against another. |
| JSON | The interchange format. Its shape is a promise — snapshot/restore reads it — so it keeps the shape it has had since W17. |
| Excel | One sheet per section, headers frozen, figures written as **numbers** with the currency in the cell's format code. A workbook whose totals row does not work is a workbook nobody asked for. |
| Word | The prose sections in order, with the tables, header rows repeating across pages. For the reader who has to change it. |

**The exporters read one assembled report, never the database.** `ReportAssembler` produces the figures, one projection arranges them into ordered sections, and each exporter only knows how to write a heading, a paragraph and a table in its own format. An exporter that re-queried would be a second source of truth for the same figures, and a second source of truth eventually disagrees.

**`ReportOptions` binds all five.** An export carries the panel's current options, so the file a reader takes away is the document they were looking at. An export that silently ignored "include shelved" is the failure W33 named for the download button. **There is no exception since W80**: `figures` was the one option that bound nothing anywhere, and rather than binding it in the exports alone — which would have made the file disagree with the page it came from — it was removed from the panel, the payload and the export URLs alike. Every option the panel still offers is honoured in all five formats and on the page.

**Missing is a dash, in every format.** Never `0`, never blank, never `∞`. A missing impact or likelihood excludes the pair from the total rather than contributing zero; writing `0` would turn "we do not know" into "we measured it and it was nothing".

**Currency is the assessment's**, formatted against a fixed `en-GB` locale to match the on-screen report — never the server's locale, which would make an export read as a different number from the page it came from.

**Generation is synchronous, measured.** A 40-control assessment (8 risks, 40 attacks, 80 edges): assemble 51ms, HTML 50ms, CSV 10ms, JSON 1ms, Excel 755ms, Word 297ms. Nothing approaches the second or two at which the answer becomes the queue and the W47 notification path; the measurement is kept as a test rather than thrown away, because it is the number the decision rests on.

**"Download report" is gone and "Print as configured" is just "Print" (D35).** W33's argument for a no-options download button was that the default path to a document should not require understanding five toggles — which the export menu now serves, where the same forced-full render is one of five formats and is named by what it produces. The qualifier on Print existed only to distinguish it from that button. The forced-full URL itself does not go away: `?download=1` still overrides every other parameter on every export route, and it is the menu's **Everything expanded** item.

### Written executive summary, and the drafted one (W54)

A **prose executive summary** sits above the computed figures, because that is the order a report is read in: a few paragraphs in the author's words, then the numbers they refer to. It is an ordinary field of the assessment — anyone with `update` writes it, edits it and clears it — and on the overwhelming majority of deployments, which run no inference runtime at all, that is the whole of this feature.

Where an assistant _is_ configured, a **Draft with the assistant** action appears beside it. It queues a job and returns immediately: the generation takes minutes (249s for a 73-node assessment on the recommended model, and it grows with the assessment), so there is no spinner and no request held open. The finished draft arrives as a **notification**, which links to the report rather than to a node.

**The draft is never the document, and that is the package.**

- It lands in its own column and is shown in its own panel, beside the live summary rather than in place of it. A job finishing four minutes after it was asked for must not overwrite what the author has typed since.
- **Edit and use** opens the draft _in the editor_. Saving is a separate, deliberate act. There is no one-click publish, for the same reason job B has no "accept all": the person reading the text is the only control that catches an instruction planted in a node description, and the spike measured one of three models carrying exactly that into summary prose.
- The draft reaches only people who could accept it. A public reader or a share-link visitor is not sent it at all — the server withholds it rather than the template hiding it.

**Disclosure is stated twice, and both print.** A line beside the summary says it was drafted by a local model and edited by the author; the methodology appendix opens with the long form, naming which section was machine-drafted and stating that the author is responsible for it. The mark is **sticky**: accepting a draft sets it, editing the text afterwards does not clear it, and only clearing the summary clears it. A mark that any edit removed would be a mark that never survived normal use, and the disclaimer exists for the reader rather than for the author's sense of authorship.

Nothing records _which_ model wrote it. The assistant is optional configuration that may be removed tomorrow; the text, the mark and the disclaimer all survive its removal, and the Draft action simply stops being rendered.

### Progress, and stopping (W58)

Every generation reports what it is doing, and every generation can be stopped. This is not polish: CPU inference runs for minutes, and a spinner that says nothing for four minutes is indistinguishable from a hang — after which the user reloads and clicks again, which costs the shared machine another four minutes.

While one runs, the panel shows **elapsed time first**, then a bar drawn against the configured token budget, then a **Stop**. The bar is labelled as a ceiling and never reaches 100% while work is still running: the budget is an upper bound, most generations finish short of it, and a full bar beside an unfinished job is the classic progress-bar lie.

- **Stop stops the generation**, not merely the waiting. Closing the tab leaves llama.cpp generating and the per-user lock held; the Stop sends a request, the running generation notices on its next token, and the lock is released on the way out.
- **A stopped generation is not an error.** No red message, nothing reported, nothing logged as broken. The person asked for it.
- **Job C can be stopped while it is still queued.** A draft may wait minutes for a worker, which is exactly when somebody realises they did not mean to ask; the cancellation survives the wait and the job checks it before spending anything.
- **The scope differs by job, deliberately.** Jobs A and B are the caller's own request: only they see it, only they stop it. Job C belongs to the **assessment** — anyone who could have started a draft can see it and stop it, because a four-minute generation started by someone who has since closed their laptop must be stoppable by whoever is still in the room.
- Progress is **polled every two seconds** on an ordinary authenticated rate limit, not the assistant's 6/min inference limit — watching a four-minute job must not exhaust the budget for asking about it. A failed poll changes nothing on screen: the generation is happening on the server either way.

### The forced-full render

`GET /assessments/{uuid}/report?download=1`, and the same parameter on every export route. One click, no options step. Reached from the export menu's **Everything expanded** item since W74 (D35) and from each row of the assessment list; the button that used to carry it on the report page is gone, the URL is not. `download=1` is resolved in `ReportOptions::fromQuery()` and **overrides every other parameter** rather than merging with them — baseline `both`, shelved included, comments included, methodology included. **Assignees are the one exception and stay off**: everything else `download=1` forces is a matter of showing more of the assessment, while that one adds people's names to a document about to leave the room, which has to be chosen rather than inherited from a button labelled Download. A stale link carrying `baseline=current` must not be able to produce a document that looks complete while silently omitting the target state.

In that mode the page also hides the options panel entirely (not merely `no-print` — the state it would show is not editable on this path), sets the document title to `ArgueSecure — {title} — {YYYY-MM-DD}` because that is where every browser gets the suggested PDF filename, and calls `window.print()` once on mount, guarded in `sessionStorage` so a back-navigation does not reopen the dialog.

### Print and PDF

A **Print** button calls `window.print()` against a dedicated `resources/css/print.css`. It prints exactly what the options panel is currently set to. It was called "Download PDF" until W35 — which is what made it indistinguishable from the Download button beside it — and "Print as configured" until W74 removed that button (D35), at which point the qualifier had nothing left to distinguish it from.

- `@page { margin: 18mm 15mm; }` with named page breaks between sections.
- `thead { display: table-header-group; }` so long tables repeat headers.
- `break-inside: avoid` on cards, table rows and chart blocks.
- Expand link URLs after their text.
- Force light-mode colours regardless of the viewer's theme.
- Hide all navigation, actions and the options panel.

This deliberately keeps a headless-Chromium container out of the deployment and keeps the legacy `dompdf 0.6` — the most dangerous dependency in the old repository — permanently gone. A Gotenberg sidecar remains available as an optional compose profile later, reusing this same stylesheet.

**Show the user a one-line hint** the first time they use the button: _"Choose 'Save as PDF' as the destination."_ Do not assume it is obvious.

### Printing the chart and the list (W49)

All three views print, through the same `print.css` and the same browser pipeline — **D6 stands: no renderer, no new dependency.** The stylesheet is loaded by `AuthenticatedLayout` rather than only by the report page; its report rules are scoped to `.report-*` and its editor rules to `.editor-print`, so neither can disturb the other's page breaks.

Common to both editor views:

- The sidebar goes with its **gutter**, not on its own — hiding only the nav would leave 240px of blank paper down the left of every page.
- Search, the view switcher and the zoom controls go. The toolbar's **active-filter chips stay**: they are the only on-screen statement of what the reader is looking at, and a printed tree with three of five risks dimmed is misleading without them. Their remove-buttons print as words, not icons.
- Hover-only affordances go. They mean nothing on paper and would print as stray glyphs over the cards they belong to.
- A **running header** carries the assessment title and the date. Without it a printed chart is a picture of an unnamed thing from an unknown date — the page that ends up in a folder and cannot be trusted six months later. The report has a cover page and does not get one.

**List** — the tree indentation is plain nested `<ul>` padding and survives untouched; `break-inside: avoid` per risk block and per node card; the scroll container releases its height.

**Chart** — the transform is reset, the connectors are SVG and scale with the page, and landscape is suggested via `@page`. That rule is teleported into `<head>` by `ChartView` while the chart is mounted rather than living in the stylesheet, because `@page` cannot be scoped by a selector and the list view — taller than it is wide — wants portrait.

**The current zoom is ignored** in favour of a fixed print size, in all three views. A page printed at someone's 140% reading zoom wastes a third of the paper; at 60% it is unreadable. The chart and list apply zoom differently (D9 — CSS `zoom` for the list, `transform: scale()` for the chart), and the report's own zoom (D43, W83) uses the list's CSS `zoom` primitive on its document body; all three are reset in `print.css`.

**A chart wider than one page tiles horizontally across several.** That is the browser's own behaviour and it is stated here rather than silently produced: the alternative, scaling a whole assessment down to one sheet, prints text nobody can read.

### Presenting the numbers honestly

The report must not overstate confidence:

- Prefix a partial total with `≥` and state coverage.
- Label every figure with its baseline.
- Never present marginal contributions as a decomposition — use log-share attribution for any "share of reduction" chart ([calculation-engine.md §9.5](calculation-engine.md#95-log-share-attribution--the-additive-metric)).
- Keep the three cost buckets separate; never print `∞` for a zero-cost control.
- Include the methodology appendix by default, stating the independence assumption and the per-occurrence definition of impact.

---

## 10. Admin and profile (W8)

**Admin** `/admin`, gated on `is_admin`:

- Users table — search, sort, paginate; name, email, admin flag, active flag, assessment count, last login, created.
- Create, edit, deactivate, delete; set or reset a password; toggle admin. Deleting a user warns about their owned assessments and offers transfer instead.
- **CSV bulk create with a preview-and-confirm step** listing every row that will be created and every row that will be rejected with the reason. The legacy importer silently swallowed bad rows; this one must not. Since 2.5.1 it opens as a dialog from the **Bulk import** button on the Users tab, not as a tab of its own; the preview is still a page.
- Credential sheet — an HTML print view for workshops. Shown once, never re-derivable, never stored in plain text, never emailed.
- **Instance settings** — about the *deployment*: whether public assessments are allowed, and session lifetime. (2.5.1 removed `default_scale_calibration` — there is one default set — and moved the assistant's switches to their own tab.)
- **Defaults** (W87, D47) — about the *content a new assessment starts with*: the default currency, and the bands of the default system scale set. It mounts the **same** band editor the assessment gets (§8, "The scale cards"), because two editors for one job is how they diverge.

  **Defaults, not global settings (2.5.1).** Saving a band first gives every assessment still pointing at the shared rows its own copy of them as they were (`SystemScaleCalibrator::detachFollowers()`, through `ScaleCalibrator`'s clone-and-repoint path), and only then edits the shared rows. So a change reaches assessments created afterwards and an assessment's "Reset to default" — nothing else — and the page says so in the same words it uses for the currency. Through 2.5 the edit moved every uncalibrated assessment's figures, and a warning box said so.
- **AI assistant** (2.5.1) — the assistant's switches. On a deployment without an inference runtime it shows one line, "AI service not installed", with a tooltip pointing to the README's *AI assistant* section; installation instructions are for whoever holds the compose file, not for this page.

  There is no "Reset to default" here. This *is* the default; a reset would need a default behind the default, and the seeder is not something the application can reach at run time.

**The assistant's four switches** (W50; W87, D48). D25's three conditions are unchanged — the deployment has a runtime, an admin switched the assistant on, the runtime answers — and W87 adds a **fourth, per feature**: help, suggestions, summary. Help costs a corpus read, a suggestion about 30 seconds of CPU and a drafted summary about 250, so an admin may reasonably want the cheap one and not the expensive one.

Every one of them can only ever **subtract**. No value stored here makes a feature work on a deployment with no inference container: `Availability` requires all four together and is the only thing any call site asks, and `UpdateSettingsRequest` validates each flag as `declined` where `config('assistant.enabled')` is false rather than storing a `true` every read path would then have to second-guess. The three render nested inside the assistant panel and disabled while the assistant itself is off, and — like the assistant toggle — they do not render at all on a deployment with no runtime.

They default **on**, unlike `assistant_enabled`, which defaults off. The instance switch is the decision; these are only read once it has been made, and defaulting them off would make enabling the assistant a two-step act whose second step is invisible.

**Profile** `/profile` — display name, email, change password (requiring the current one), theme, active sessions with revoke, delete account with a confirmation naming what will be deleted.

**Sharing dialog** (W9) — search existing users, add with a role, change or revoke, transfer ownership; public toggle; read-only link with copy and revoke; collaborator list showing role and inviter.

---

## 11. Accessibility

Not optional — this tool is used in teaching, and some users will rely on these.

- Every action reachable by keyboard; visible focus rings throughout.
- The tree is an ARIA `tree` with `treeitem` roles, `aria-expanded`, `aria-level` and `aria-selected`. Shared nodes carry an `aria-description` naming their other positions, since the hover-highlight affordance is unavailable to keyboard and screen-reader users.
- Live regions announce collaborator changes and save state, politely.
- Colour is never the sole carrier of meaning.
- Contrast meets WCAG AA in both themes.
- Respect `prefers-reduced-motion` — disable the canvas and expand transitions.
- The modal traps focus and restores it to the triggering element on close.
