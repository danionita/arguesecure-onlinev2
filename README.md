# ArgueSecure

ArgueSecure is a collaborative platform for security risk assessment. A team
maps what could go wrong with a system, puts numbers on it, and decides what
to do about it. Every assessment has the same shape:

**Target of Assessment → Risks → Attacks → Defences / Transfers**

A *risk* is a loss you care about, an *attack* is a way it can happen, a
*defence* is a component or decision that reduces it, and a *transfer* shifts
the loss to someone else, such as an insurer. Give risks an impact and attacks
a likelihood, and ArgueSecure works out the expected annual loss three ways:
with no defences, with what is implemented today, and with everything on the
roadmap done.

## What it does

- **Three views of one assessment.** The **chart** is a board for a workshop
  room, the **list** is for filling in values, and the **report** (Beta)
  presents the result.
- **Honest numbers.** Results are annual losses in your currency, never
  low/medium/high bands. A missing value is flagged and never counted as
  zero. Every defence is ranked by what it saves and what it costs.
- **Built together, live.** You see who else is in an assessment. Each node
  is edited by one person at a time. Comments support @mentions, and nodes can
  be assigned to people. Notifications tell you when something needs you.
- **Nothing is lost.** Every change is recorded. Versions are saved
  automatically and whenever you ask, and any version can be compared or
  restored. Structural changes can be undone.
- **Start from a template.** Twelve are bundled, among them complete
  gap-analysis templates for ISO/IEC 27001, GDPR, NIS2 and the CIS Controls.
  You can also pull individual risks, attacks or defences from any template
  into an assessment you already have.
- **Share on your terms.** Assessments are private until you share them. You
  can share with named people as viewer, commenter, editor or manager, publish
  a read-only link, or make an assessment public, which is read-only.
- **Take it with you.** Export to HTML, CSV, JSON, Excel or Word, or print any
  view to PDF.
- **An optional local AI assistant.** It can answer questions about the help
  pages, suggest risks, attacks and defences, and draft a report's executive
  summary. It runs on your own hardware and is off unless you install it.

The in-app help (`/help`) explains the concepts, the quantification model,
the keyboard map, collaboration, sharing and reports.

ArgueSecure 2 is a rewrite on Laravel 12, PHP 8.3, Inertia and Vue 3. The
1.x application (Laravel 5.2, jQuery) lives on only in this repository's git
history, at the `v1-final` tag.

## Installing

You need Docker and Docker Compose, nothing else. PHP, Node, MariaDB and
Redis all run in containers.

**From the published image.** Every release is published to GitHub's
container registry, so a deployment needs two files and no build. Put
[`docker-compose.yml`](docker-compose.yml) and
[`.env.example`](.env.example), renamed to `.env`, in one directory, fill in
`.env`, and start it:

```bash
docker compose pull
docker compose up -d
```

Set `ARGUESECURE_VERSION` in `.env` to the release you want (`2.7.0`, or
`2.7` to take its patch releases); `latest` follows the newest release. While
the repository is private its images are too, so the host must first run
`docker login ghcr.io` once with a GitHub token that has only the
`read:packages` scope.

**From source.** A checkout builds the same images itself:

```bash
git clone https://github.com/danionita/arguesecure-onlinev2.git
cd arguesecure-onlinev2
cp .env.example .env
docker compose up --build -d
```

On its first start the stack:

- generates an `APP_KEY` and the three `REVERB_*` secrets and logs them
  prominently. Keep them: losing them invalidates every encrypted cookie.
- runs the database migrations.
- starts six containers: the app (nginx and php-fpm), Reverb (websockets), a
  queue worker, a scheduler, MariaDB and Redis. Only the app publishes a port.

Open `http://localhost:8000`, or whichever port you set as `APP_PORT`.

### The first administrator

There is no default account, and none is ever seeded. While the `users`
table is empty, the app shows a setup screen where you create the first
administrator. Creating it also loads the bundled templates. After that,
administrators create accounts under **Admin → Users**, one at a time or in
bulk from a CSV file. There is no public registration.

### Behind a reverse proxy

To serve ArgueSecure through SWAG, nginx, Traefik or similar, set `APP_URL`
in `.env` to the public HTTPS address. The proxy-trust configuration this
relies on is in [`docs/specs/deployment.md`](docs/specs/deployment.md) §4.

Nothing in the repository hardcodes a host, domain or path. `DATA_PATH` in
`.env` sets where persistent data (`storage/`, the database, Redis) is
bind-mounted, and defaults to `./data`.

## Templates

Every signed-in account can start an assessment from a template. The **Add
from template** button on a node, and **From template** in the new-node
dialog, also let you copy a template's risks, attacks or defences into an
existing assessment. Only administrators can publish an assessment as a
template.

| Template | What it contains |
|---|---|
| Northwind Outfitters (demo web shop) | A worked example of an assessment in progress |
| Four system archetypes | Public web application, multi-tenant SaaS, corporate internal network, connected home device |
| ISO/IEC 27001:2022 | Clauses 4–10 and all 93 Annex A controls (ISO/IEC 27002 guidance) |
| ISO/IEC 42001:2023 | Clauses 4–10 and all 38 Annex A controls of the AI management system standard |
| EU AI Act | The operator obligations of Regulation (EU) 2024/1689 |
| SOC 2 | All 61 Trust Services Criteria: CC1–CC9, Availability, Confidentiality, Processing Integrity and Privacy |
| GDPR | The controller and processor obligations of Regulation (EU) 2016/679 |
| NIS2 | The obligations Directive (EU) 2022/2555 places on essential and important entities, including the Article 21 measures and incident reporting deadlines |
| CIS Controls v8.1 | All 18 Controls and 153 Safeguards, with the 56 Implementation Group 1 safeguards marked |

The seven standards templates are gap-analysis starting points. Each control
or obligation is a defence set to *todo*, placed under the threats it
addresses. Mark what you already have as *implemented* and shelve what is out
of scope. Their descriptions summarise the controls; they are not the text of
the standards, and not legal advice.

To load templates added in a later release into an instance whose first
administrator already exists, run the seeder below. It is idempotent and
creates no users:

```bash
docker compose exec app php artisan db:seed --class='Database\Seeders\SampleContentSeeder' --force
```

Plain `php artisan db:seed` does nothing useful here. The default seeder
used to create an administrator account, which this project never does.

## AI assistant (optional)

ArgueSecure can use a language model running **on your own machine**, through
`llama.cpp`, to:

- answer questions on the help pages,
- suggest risks, attacks and defences,
- draft a report's executive summary.

It is off and absent by default, and an instance without it is fully
supported; **Admin → AI assistant** then reads "AI service not installed".
Nothing is sent anywhere else, and nothing the model writes is saved until a
person has read it and accepted it.

There are two presets, so you choose a memory budget rather than a file name:

| Preset | Needs about | |
|---|---|---|
| `small` (recommended) | 6 GiB RAM, a 4.2 GB download | Runs on almost any host that can spare the memory |
| `large` | 28 GiB RAM, a 14.2 GB download | Better suggestions and summaries, for a host with memory to spare |

To add it:

1. Install a preset. This one command downloads it from the publisher,
   checks its size and checksum, and records the choice — there is no manual
   download or size check to get wrong:

   ```bash
   docker compose run --rm assistant-install small   # or: large
   ```

2. Set `ASSISTANT_ENABLED=true` in `.env`, then start the assistant:

   ```bash
   docker compose --profile assistant up -d
   ```

   (If it was already running — for example while switching models — use
   `docker compose --profile assistant restart assistant` instead.)

   It uses four CPU cores. Set `ASSISTANT_THREADS` in `.env` to give it more
   or fewer; on a host with fewer than four, lower it or the container will
   not start.

3. Sign in as an administrator, open **Admin → AI assistant** and turn on
   **Enable the assistant**. Each of the three features can also be switched
   off on its own. The tab shows which model the runtime actually loaded.

**Switching preset later:** run the install command for the other preset,
then restart the assistant as in step 2.

**Upgrading a 2.5 deployment:** nothing changes on its own — it keeps
running the model it ran before, however its `.env` named it.
To move to a preset instead, remove `ASSISTANT_MODEL_FILE` and
`ASSISTANT_MODEL` from `.env`, run the install command, and restart the
assistant; you can then delete the old model file from the models directory.

[`docs/ASSISTANT.md`](docs/ASSISTANT.md) explains each switch, what each
feature costs, and what the assistant is never allowed to do.

## Running it day to day

```bash
# Follow the logs
docker compose logs -f app

# Upgrade (migrations run when the app container starts). Take a database
# dump first (below), and set ARGUESECURE_VERSION if you pin one.
docker compose pull && docker compose up -d

# ...or, from a source checkout (add --profile assistant to both commands
# if you run the assistant, or its image keeps the old launcher)
git pull && docker compose build && docker compose up -d

# Stop, keeping the data in DATA_PATH
docker compose down

# Stop and delete all data. This cannot be undone.
docker compose down -v
```

### Backups

Take a database dump before every upgrade:

```bash
docker compose exec db mariadb-dump -u"${DB_USERNAME}" -p"${DB_PASSWORD}" "${DB_DATABASE}" > backup.sql
```

Versions on an assessment's History page protect against mistakes inside the
application, not against losing the server, so they are not a backup. A
single assessment can also be exported as JSON from its **Export** menu, a
portable copy of all its data. A scheduled export of every assessment is
planned (see [`docs/PLAN.md`](docs/PLAN.md) Phase 10) but not built. Until
then, the database dump is the backup.

## Development

Development runs on the same Docker Compose stack.
`docker-compose.override.yml` also publishes MariaDB and Redis on
`127.0.0.1`, so a local PHP 8.3 and Node 22 toolchain can run the test suite
against them. [`CONTRIBUTING.md`](CONTRIBUTING.md) covers the setup and the
workflow, and [`docs/specs/deployment.md`](docs/specs/deployment.md)
describes the service layout.

Every change must pass all of these:

```bash
# Backend
vendor/bin/pint --test           # code style
vendor/bin/phpstan analyse       # static analysis, level 6+
php artisan test                 # Pest

# Frontend
npm run lint:check               # ESLint, no warnings allowed
npm run format:check             # Prettier
npm run type-check               # vue-tsc --noEmit
npm run test:unit                # Vitest
```

The end-to-end suite (`tests/e2e`) drives a real browser against its own
throwaway stack; read [`tests/e2e/README.md`](tests/e2e/README.md) before
running it.

## Documentation

| Document | Covers |
|---|---|
| [`docs/PLAN.md`](docs/PLAN.md) | The rewrite plan, its decisions and its work packages |
| [`docs/RELEASE-2.6.2.md`](docs/RELEASE-2.6.2.md) | The latest release notes, and what is still open |
| [`docs/specs/schema.md`](docs/specs/schema.md) | Database, models and authorization |
| [`docs/specs/calculation-engine.md`](docs/specs/calculation-engine.md) | The quantification model (normative) |
| [`docs/specs/ui.md`](docs/specs/ui.md) | The editor, the keyboard map and reports |
| [`docs/specs/security.md`](docs/specs/security.md) | Authentication, sessions, headers and input handling |
| [`docs/specs/deployment.md`](docs/specs/deployment.md) | Docker, CI, environment and operations |
| [`docs/ASSISTANT.md`](docs/ASSISTANT.md) | The optional AI assistant |
| `/help`, in the running app | User help: concepts, quantification, keyboard map, collaboration, sharing, templates, history and reports |

## Background and credits

ArgueSecure was created by **Dan Ionita** at the University of Twente, from
research into argumentation-based security risk assessment carried out in the
EU FP7 project **TREsPASS** (*Technology-supported Risk Estimation by
Predictive Assessment of Socio-technical Security*, grant agreement
no. 318003). The method and the first versions of the tool are described in:

> D. Ionita, R. Kegel, A. Baltuta and R. Wieringa, "ArgueSecure: Out-of-the-Box
> Security Risk Assessment," *2016 IEEE 24th International Requirements
> Engineering Conference Workshops (REW)*, Beijing, 2016, pp. 74–79.
> doi:[10.1109/REW.2016.027](https://doi.org/10.1109/REW.2016.027)

ArgueSecure 2 is a rewrite of that tool. If you use it in research, please
cite the paper above; [`CITATION.cff`](CITATION.cff) has the details.

## License

MIT. See [`LICENSE`](LICENSE).
