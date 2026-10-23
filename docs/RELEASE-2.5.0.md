# ArgueSecure 2.5.0

One set of actions everywhere, the report as a proper third view, simpler
scales and an assistant you can actually reach from the chart.

2.4 was never released on its own, so this release also carries everything in
[`RELEASE-2.4.0.md`](RELEASE-2.4.0.md).

## The same actions wherever you are

- **One action bar** (Settings, History, Share, Export, Print) in the editor
  and in the report, with the assessment list offering the same verbs. History
  and Assessment settings left the application sidebar, where they sat one
  level above the thing they belong to.
- **The report is the third view.** It keeps the sidebar for signed-in readers,
  appears without it for people with a share link, and has search, an
  always-visible options row and its own zoom.
- **The assessment list** offers Settings, Share and Export.
- **Assessment settings** has a "Return to assessment" link. The title and
  description are edited on the assessment itself, in one place only, and the
  history page no longer records a title edit twice.

## Comfort

- The list has more room and opens larger, and both the list and the chart
  centre on the page when they are narrower than it.
- **Zoom is remembered separately for the chart and the list.** A zoom you set
  earlier carries over to both.
- Shared items show one compact tag with the count.
- **The assistant is on the chart.** Suggestions were previously available
  only in the list. A new item also offers an *AI suggestion* button before you
  type, only where the assistant is available. Suggestions are accepted one at
  a time, never all at once.
- The empty state of the templates page no longer sits against its border.

## Scales

- **Edited from Application settings,** with no colour or code inputs, and
  resettable to the defaults.
- Administrators can set the defaults used by new assessments in a new
  **Defaults** tab.
- Per-feature switches let an administrator turn off the assistant's help,
  suggestions or summary individually.

## Fixes

- **No more false edit conflicts.** Typing quickly in the item window could
  make your own earlier save look like someone else's edit. Saves are now
  ordered per item, and a real second editor still gets the conflict notice.
- **A printed report no longer carries the editor's running header,** and the
  view switcher no longer sits lower on the report than in the list.

## Upgrading from 2.3.1

- **Update the images and restart.** The Excel and Word exports need the
  image's graphics extension, so an old image will not run the new code.
- **Templates already loaded keep their old content.** To get the reference and
  template fixes, reload the template; see the README's section on templates.
- A database change adds three switches for the assistant's features. All
  default to on, but the assistant itself stays off until you turn it on, so
  nothing changes at upgrade.
- **The separate page for an assessment's scales is gone.** A bookmark to it
  now shows "not found"; scales are on the Assessment settings page.
- Stored zoom preferences carry over by themselves.
- Scale level codes are no longer shown anywhere, including exports.
- Nothing in how risk is calculated changed.

## Known limits

- Zoom in the report scales the type size, not the page width.
