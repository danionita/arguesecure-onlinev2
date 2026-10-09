# Administration

**Admin**, in the sidebar, is shown to administrators only. Administrators
can open, edit and manage every assessment on the instance.

## Users

There is no public sign-up: every account is created here.

- **Create** an account, or **Bulk import** several from a CSV file. The
  import shows a preview of every row it will create and every row it will
  reject, with the reason, before anything is written.
- New passwords are shown **once**, on a sheet you can print for a workshop.
  They are never stored in plain text or emailed.
- Edit, deactivate, reset a password, make someone an administrator, or
  delete an account. Deleting a user who owns assessments offers to transfer
  them first.

## Instance settings

Settings for this deployment: whether assessments may be made public, and
how long a session lasts.

## Defaults

What a new assessment starts with: the default **currency** and the default
**value scales** (the bands for impact, likelihood and defence strength).
Changing them affects assessments created afterwards, and an assessment's
*Reset to default*. Existing assessments keep the values they have.

## AI assistant

Switches the optional local assistant on or off, and each of its three
features — help answers, suggestions and summary drafts — separately. If
the server has no model installed, the tab says "AI service not installed";
installing one is done on the server, as the project's README describes.

Once one is installed, the tab shows which model the server is actually
running: the **small** model, which needs about 6 GiB of memory, or the
**large** one, which needs about 28 GiB and writes better suggestions and
summaries. Switching between them is also done on the server.

## Templates

Only administrators can publish an assessment as a template, from that
assessment's **Share** dialog. See [Templates](/help/templates).
