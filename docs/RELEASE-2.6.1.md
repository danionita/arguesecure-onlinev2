# ArgueSecure 2.6.1 — the first published images

> What `PLAN-2.7.md`'s W101 (decisions D62–D63) shipped, ahead
> of the rest of 2.7. Follows [`RELEASE-2.6.0.md`](RELEASE-2.6.0.md).
>
> Branch: `claude/project-thread-kr71ty`, merged to `master` as `33ee785`
> (pull request #3) off `ba03957`, the 2.6.0 release commit. No application
> code changed: 2.6.1 is 2.6.0's application in a published image. The tag
> is on `71ed69a` (these notes on top of `33ee785`), not on `master`'s tip:
> W102, merged to `master` since, is 2.7 work and ships with 2.7.0.

---

## The headline

Until now, running ArgueSecure meant cloning the repository and building
four images on the host, and every upgrade meant `git pull` and another
build from a terminal. From 2.6.1 every release is published to GitHub's
container registry, and a deployment is two files — `docker-compose.yml`
and a `.env` — with `docker compose pull && docker compose up -d` as the
whole upgrade. On Unraid that is Compose Manager's own Update button.

2.6.0's tag predates the publishing workflow and cannot produce an image,
which is why this is a release of its own rather than a republished 2.6.0.

## 1. Published images (W101)

`.github/workflows/image.yml`, `docker-compose.yml`, `docker/assistant/Dockerfile`

- **Two images, one tag.** `ghcr.io/danionita/arguesecure` (app, Reverb,
  queue, scheduler) and `ghcr.io/danionita/arguesecure-assistant` always
  carry the same tag, so the app and the assistant read the same
  `models.conf`. `ARGUESECURE_VERSION` in `.env` chooses it.
- **Tags.** `v2.6.1` publishes `2.6.1`, and `2.6` and `latest` only if it is
  the newest release in that series and overall — found in review: without
  that, a later patch to an older series would have moved `latest` back
  under a database the newer release had already migrated. A manual run of
  the workflow publishes `edge`; `linux/amd64` only.
- **The acceptance test runs on every release.** Before anything is pushed,
  the workflow boots the new images from a directory holding only
  `docker-compose.yml` and a `.env`, with `--pull never`, and requires
  `/up`, the setup screen and `assistant-install list` to answer.
- **The assistant no longer needs a checkout** (D63). Its launcher and
  catalogue were bind-mounted from the clone; they are now baked into a thin
  image `FROM` the same digest-pinned llama.cpp, so no binary, flag or
  preset changed and 2.6's measurements still describe what runs.
- **Source builds are unchanged.** Every service keeps `build:` beside
  `image:`. Found in review: every compose project on a host now builds to
  the one image name, so a second project's build could retag the image a
  real stack runs; the e2e stack has its own `:e2e` tag and CLAUDE.md's
  throwaway check sets `ARGUESECURE_VERSION`. Also found: a plain
  `docker compose build` skips the profile-gated assistant, whose launcher
  now lives in its image — the README says to add `--profile assistant`.

## Upgrading from 2.6.0

**Moving to the published image** (recommended):

1. Take a database dump (README, *Backups*).
2. In the directory you deploy from, replace `docker-compose.yml` with this
   release's and add `ARGUESECURE_VERSION=2.6.1` (or `2.6`) to `.env`. A
   source checkout can simply `git pull`.
3. While the repository is private its images are too: run
   `docker login ghcr.io` once with a GitHub token scoped to
   `read:packages`.
4. `docker compose pull && docker compose up -d` (add `--profile assistant`
   if you run it). `DATA_PATH`, `app.key` and the database carry over
   untouched.

**Staying on a source build:** `git pull`, then `docker compose build &&
docker compose up -d`, with `--profile assistant` on both if you run the
assistant.

## Verification behind this release

- On pull request #3's head (`1bcc249`, identical in content to `33ee785`),
  every CI job passed: PHP (Pint, PHPStan, Pest), JS (ESLint, vue-tsc,
  Vitest), Security, Docker build and boot, E2E (Playwright release
  scenarios), and the Image workflow's image-only boot test.
- In the cloud thread that built it: Pint, PHPStan and Pest (1,217 passed,
  2 skipped); ESLint, Prettier, vue-tsc and Vitest (904 passed); the five
  new guards in `tests/Unit/Deployment/ComposeImageDeployTest.php` each
  mutation-tested.
- **Deployability** was proven by CI, not locally: the thread's egress
  policy refuses `pecl` and GitHub archive downloads, so it could not build
  the application image itself.
- The images for this tag are built and booted again by `image.yml` before
  they are pushed.

## Still open

- Everything under 2.6.0's *Still open* is unchanged; this release touched
  no application code.
- `DEFECT:` pins (`grep -rn "DEFECT:" tests/`): one open, as at 2.6.0 —
  the unshared flash bag (`ScaleResetTest`).
- **The packages are private while the repository is.** Making the
  repository public does not change them: each package's visibility is set
  in its own settings on GitHub.
- **No arm64 image.** Deliberate (D62); revisit if a deployment needs one.
- **W100's last step** — a real Project thread running the seven checks —
  is still open; the thread that built W101 found the VM's default `php` to
  be 8.4 and GitHub archive downloads refused, which is W100 follow-up
  material.
