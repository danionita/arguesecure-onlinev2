# ArgueSecure 2.6.1

> Version history: this version predates the releases published here, and
> its note is kept to show what changed. To install, follow the README for the
> current release.

Prebuilt images: run ArgueSecure from two files, and upgrade with a pull.

Until now, running ArgueSecure meant building the images on your own host.
From this version a release is published as a set of container images, so a
deployment is a `docker-compose.yml` and a `.env`, and an upgrade is a pull and
a restart. On Unraid that is the Update button in Compose Manager.

## What changed

- **Published images** for the application and for the assistant runtime, with
  `ARGUESECURE_VERSION` in `.env` to pin a version or follow a line.
- **The compose file pulls by default.** Building from source still works.

## Changes for operators

The compose file now pulls images by default, and building from source still
works. `DATA_PATH`, the keys and the database carry over unchanged.

## Known limits

- Behind a reverse proxy, the page can come up blank for visitors on public
  addresses. This is fixed in 2.6.2; upgrade before putting 2.6.1 on the
  internet.
