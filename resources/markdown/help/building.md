# Building an assessment

## Starting one

Choose **New assessment**, and give it a title and, if offered, a **Scale**. For a ready-made structure, open
**Templates** in the sidebar — see [Templates](/help/templates).

## Adding a node

Press **+** (Add child) on a card, or `Tab` (child) or `Enter` (sibling) on a
focused row. A pop-up opens for the new node. **Nothing is created until you
press Add**: Cancel or `Escape` leaves nothing behind, and collaborators see
the node only once it has a name. After Add, the pop-up switches to the new
node so you can fill in its values.

Three things help you fill in the pop-up:

- **Link, don't duplicate.** While you type the title of an attack or a
  defence, nodes of that type already in the assessment are offered. Picking
  one links it here instead of creating a second copy.
- **From template** fills the title and description from one node of a
  template.
- **AI suggestion** (when the assistant is enabled) asks the local model for
  one and fills the form with it. Read it before you add it.

Both buttons disappear once you type a title, and neither saves anything:
you can still edit the text before pressing Add.

## Adding several at once

Next to **+** on a card:

- **Add from template** (copy icon) — choose a template, then add as many of
  its risks, attacks or defences as you like; the list filters as you type.
  If a node with the same title is already in the assessment, the button
  says **Link** and links that node instead of copying it; *Already here*
  means it is under this parent already. Values are never copied — a
  template's figures describe its example, not your system.
- **Suggest** (when the assistant is enabled) — a list from the local model,
  each added only when you press its Add.

## Editing a node

Click a node's title or description to open it on **Details**; click a
value to open the tab that holds it. In the list, double-click a
description to edit it in place. The pop-up's tabs:

| Tab | Holds |
|---|---|
| Details | Title, description, labels; for a defence, its status and the transfer switch |
| Impact / Likelihood / Cost | The value this type is quantified by |
| Strength | A defence's strength against each attack it is linked to, and why |
| Statistics | Every computed figure, inherent, current and target side by side |
| References | CVEs and other links; on a defence, the framework control it implements |
| Assignees | Who is responsible for the node |
| Comments | Threaded discussion; `@name` mentions someone |
| History | Every change to this node, each one revertable |

The root's **Scales** tab (managers only) holds the currency, risk tolerance,
amortisation period and scales: pick one or edit the bands, then save. **Delete** is in the pop-up header.

## Structure

- **Drag** a card onto another parent and choose **Move it here** or **Link
  it here as well**. In the list, drop it on a sibling to change the order.
- The **shuffle badge** on a node that appears in several places lists them,
  and detaches it from one.
- `Ctrl`/`Cmd` `+Z` undoes structural changes — adding, deleting, moving,
  linking. Field edits are reverted from the node's History tab.

## Finding your way

Search with `/`. **Filters** dim nodes that don't match a label, status,
type or *missing values*. Collapse branches (a collapsed card shows a small number: how many
children it is hiding; click it to expand), focus on one subtree, and zoom
each view separately.
