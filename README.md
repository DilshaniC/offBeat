# Offbeat — Makandura

A complete university project for discovering and publishing quiet, lesser-known places around Makandura, Sri Lanka. Built with **HTML, CSS, vanilla JavaScript, PHP 8.3 and MySQL**, with Leaflet/OpenStreetMap maps. No application framework, Node build step, booking system or online payment gateway.

The supplied specification is [main.pdf](main.pdf). `main.tex` was not present. Its extracted text is preserved in [docs/SRS-extracted.txt](docs/SRS-extracted.txt). The user's traveller-contribution and offline-advertising requirements extend the original SRS.

## Start locally

Run the launcher for your operating system from this directory. It creates the ignored `.env`
secrets, starts the stack, applies the schema, seeds demonstration content and ensures the test
logins below exist. Re-running it is safe: existing content and credentials are preserved.

| Platform | Command |
| --- | --- |
| Linux / macOS | `./start.sh` |
| Windows (PowerShell) | `.\start.ps1` |

Then open **http://localhost:8088**.

If PowerShell refuses to run the script, launch it once as
`powershell -ExecutionPolicy Bypass -File .\start.ps1`.

### Launcher options

| Option (`start.sh`) | Option (`start.ps1`) | What it does |
| --- | --- | --- |
| *(none)* | *(none)* | Docker stack from `compose.yaml`, building the PHP image against MySQL 8.4. |
| `--compat` | `-Compat` | Docker stack from `compose.compat.yaml`, reusing pre-pulled `php:8.3-apache` and `mysql:5.7` images. Use this when image pulls or layer extraction fail. |
| `--native` | `-Native` | No Docker. Serves `public/` with PHP's built-in server against a MySQL/MariaDB already running on the host. Needs PHP 8.1+ with `pdo_mysql` and `fileinfo`, plus a `mysql`/`mariadb` client on `PATH`. |
| `--stop` | `-Stop` | Stops whatever the launcher started. The database volume and uploads are kept. |

To use a different port, edit `APP_PORT` in `.env` before starting. `--stop` never passes `-v` to
`docker compose down`; do not add it unless you intend to erase the database.

`python3 scripts/start.py [--compat]` remains available and does the same Docker startup without
the prerequisite checks, the native mode or the demo logins.

## Test login details

`scripts/demo-login.php` runs on every launch and guarantees these two fixed accounts exist. They
are **for local demonstration and marking only**. The passwords are committed to this repository,
so treat any deployment that has them as public.

| Role | Email | Password |
| --- | --- | --- |
| Administrator | `demo.admin@offbeat.local` | `OffbeatAdmin!2026` |
| Traveller | `demo.traveller@offbeat.local` | `OffbeatDemo!2026` |

Each launch resets these two passwords back to the values above, so they keep working after you
change them while testing. No other account is touched.

Separately, the launcher generates a **private** administrator with a random password and writes it
to `storage/local-access.json` (file mode `600`, git-ignored). That account is the one to use if you
want an administrator whose password was never published. You can also register your own traveller
account through the site.

The script refuses to create the fixed accounts when `APP_ENV=production`. Before hosting this
anywhere real, delete both demo users and rely on `scripts/create-admin.php` instead.

### Changing a password, or recovering a locked-out account

There is no self-service "forgot password" email flow; that is deliberate, since the project ships
no mail delivery. Three paths exist instead.

**1. Change your own password while signed in.** Account → **Profile** tab. Enter your current
password plus the new one. Minimum 10 characters.

**2. Reset any password from the command line.** `scripts/reset-password.php` is the recovery path,
including for the last remaining administrator. Run with no arguments to list every account:

```sh
docker compose -f compose.compat.yaml exec -T web php scripts/reset-password.php
```

Then reset the one you want. New passwords must be 12-72 bytes:

```sh
docker compose -f compose.compat.yaml exec -T \
  -e RESET_EMAIL=demo.admin@offbeat.local \
  -e "RESET_PASSWORD=your-new-password-here" \
  web php scripts/reset-password.php
```

On the `--native` launcher, drop the `docker compose ... exec -T web` prefix and run
`RESET_EMAIL=... RESET_PASSWORD=... php scripts/reset-password.php` directly.

If the account is suspended it still cannot sign in after a reset; the script says so and you can
add `-e RESET_ACTIVATE=1` to reactivate it in the same step. Because this needs shell access to the
server, it is only as private as the machine itself — that is the intended trust boundary.

**3. Create an additional administrator.** `scripts/create-admin.php` makes a new admin account but
refuses an email that already exists, so it cannot be used to take over an existing login. See
[Administration](#administration).

> Administrators cannot reset another user's password through the web interface. `user_status` only
> suspends and reactivates accounts. Password resets are CLI-only, by design.

### Compatibility startup on this workstation

This workstation's Docker image store failed to unmount extracted MySQL 8.4 layers and stalled a build-layer commit. Its existing MySQL 5.7 image and downloaded PHP 8.3 image support an isolated compatibility stack:

```sh
./start.sh --compat
docker compose -f compose.compat.yaml ps
```

The application was executed and tested on that stack at port 8088. It does not connect to or change the workstation's other database containers. Use `docker compose -f compose.compat.yaml ...` for commands against it. Choose either normal or compatibility startup; both publish the same port. The normal deployment configuration targets MySQL 8.4. The compatibility database is for local demonstration and testing.

## Features

- Public discovery, keyword/category/region and distance filtering, photo galleries, interactive attraction and service maps.
- Detailed descriptions, map coordinates, Colombo travel directions, visiting duration, access and safety notes, and site-specific conservation guidance.
- Nearby hospitals, police, bus stations, shops, rental vehicles, tuk-tuk drivers and guides, with contact links where known.
- Traveller registration/login, editable profile, saved places, own submissions and image uploads; new and edited submissions require admin review.
- One-day itinerary suggestions with visiting order, arrival/departure estimates, map and printable view; guests can generate, signed-in travellers can save/delete plans.
- Administrator content and service CRUD, submission approval/rejection with feedback, traveller suspension/reactivation and visitor reports.
- Public business marketing inquiries. Only the admin records offline payments and dated sponsorships. Active promotions are labelled; future, expired and cancelled promotions are inactive. Essential services cannot be sponsored. Payment history survives removal of a service or place.
- Responsive layouts, keyboard-visible focus, labelled forms, readable feedback, empty/error states and coordinate/direction fallbacks when map tiles cannot load.

## Demonstration content and scope

The SRS mentions an associated local dataset and proposal, neither of which was provided. The ten seeded places are **fictional demonstration entries**, with illustrative coordinates clustered near the project area and bundled stock nature photographs. They are deliberately labelled in the interface. Routes, access conditions and coordinates are not field verified. No emergency telephone numbers or real local providers were invented. The administrator can replace entries with verified destinations and add actual services.

This implements the data-entry and publishing workflow needed for the SRS's ten real local destinations; the missing field dataset remains a content prerequisite for real tourism use. [Image provenance](docs/IMAGE-SOURCES.md) records the bundled photography. No online payments, bookings, live traffic or guaranteed road routing are claimed.

The planner uses nearest-neighbour ordering and estimates each leg as straight-line distance × 1.35 at an assumed 30 km/h, plus each visit duration. It warns about long/late days and excludes the return journey. Check written route/access information and external navigation before travelling.

## Administration

Sign in as an administrator and open **Admin** in the navigation. The console has seven tabs:

| Tab | What it does |
| --- | --- |
| Overview | Counts of pending reviews, published places, people, services, open reports and open inquiries. |
| Submissions | The moderation queue, pending first. Approve, or reject with a note the contributor sees. View and edit any place. |
| Services | Create, edit and delete local services, optionally attached to a place. Shows which are currently sponsored. |
| Travellers | Suspend and reactivate traveller accounts. Administrator accounts cannot be changed here. |
| Reports | Accuracy reports from visitors; resolve or dismiss. |
| Inquiries | Business advertising inquiries; mark contacted or closed. |
| Sponsorships | Record offline payments and cancel campaigns. |

Sign in using the generated account, then open **Admin**. Review a pending traveller place, inspect its details and uploaded images, and approve or reject with a note. Travellers can edit only their own places. Editing an approved place removes it from public discovery until reviewed again.

For advertising, review the inquiry, contact the business outside the system, create/update its service listing, then record the offline receipt, amount in LKR, payment date and promotion dates. Recording an active-date payment enables the sponsored label automatically. Cancel a mistaken campaign through the payment list. Private receipt references and amounts appear only to the administrator.

To create another administrator without exposing a role selector on public registration, set `ADMIN_EMAIL`, `ADMIN_PASSWORD` and optionally `ADMIN_NAME` in your shell, then:

```sh
docker compose exec -T -e ADMIN_EMAIL -e ADMIN_PASSWORD -e ADMIN_NAME web php scripts/create-admin.php
```

Existing accounts are preserved; the command never silently promotes a traveller.

## Conventional PHP / Apache hosting

1. Use PHP 8.3+ with PDO MySQL and fileinfo, MySQL 8.x or compatible MariaDB, and Apache with `.htaccess` support. Install `database/schema.sql` in an empty UTF-8 database.
2. Set the web document root to **`public/`**, keeping `.env`, `app/`, `database/`, `scripts/` and `storage/` outside public access.
3. Copy `.env.example` to `.env`; set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` and `APP_ENV=production`. Use a dedicated database account. `.env` stays out of version control.
4. Make `public/uploads/` writable by PHP, preserve its `.htaccess`, and apply the upload limits in `docker/php.ini`. Uploads allow up to eight JPEG/PNG/WebP images, 5 MB each and 24 MB combined, with dimension limits.
5. Create an admin using `scripts/create-admin.php`. Seed demonstration content only if desired, using `SEED_ADMIN_EMAIL`/`SEED_ADMIN_PASSWORD` and `php scripts/seed.php`.
6. Serve through HTTPS. If TLS terminates at a reverse proxy, configure Apache/PHP's trusted HTTPS indication so session cookies are marked Secure. Do not trust arbitrary client-supplied forwarded headers.

PHP sessions use HttpOnly/SameSite cookies and regenerated IDs. All mutations require CSRF; queries are parameterized; ownership and role checks are server-side. Output is escaped, uploaded filenames randomized, executable uploads blocked, and public errors omit database details. Public auth/inquiry/report actions have database-backed rate limits. The project intentionally does not include email delivery or self-service password reset; users can change their password after signing in.

## Verification and maintenance

Install test dependencies in a separate Python virtual environment:

```sh
python3 -m venv /tmp/offbeat-test-venv
/tmp/offbeat-test-venv/bin/pip install requests playwright
/tmp/offbeat-test-venv/bin/python -m playwright install chromium
/tmp/offbeat-test-venv/bin/python scripts/test.py
```

`scripts/test.py` reads ignored local credentials and executes real HTTP/database workflows in `tests/api_smoke.py`. Tests create marked test users/content; run against a local development instance. The cleanup command removes only marked smoke fixtures and resets local rate limits:

```sh
docker compose exec -T web php scripts/cleanup-tests.php --confirm-test-cleanup
```

It requires `APP_ENV=local`. Test reports are stored in ignored `storage/`; milestone evidence and known limitations are recorded in [docs/VERIFICATION.md](docs/VERIFICATION.md). [docs/REQUIREMENTS.md](docs/REQUIREMENTS.md) maps the SRS to implementation. [docs/TOURIST-REVIEW.md](docs/TOURIST-REVIEW.md) contains the explicitly simulated tourist panel and engineering consultations.

Back up both the MySQL database and `public/uploads/`. Use the database provider's dump/restore tools with credentials from protected configuration; do not put secrets into committed commands. Versioned schema installation is in `database/schema.sql`. The app reads environment variables first and supports `.env` for local convenience.

## Continue development

Read [docs/HANDOFF.md](docs/HANDOFF.md) for progress, reproducible commands, verification evidence and outstanding limitations, then [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for the API contract. Source areas:

| Area | Location |
| --- | --- |
| PHP API and authorization | `public/api.php`, `app/` |
| Browser interface | `public/index.php`, `public/assets/app.js`, `public/assets/style.css` |
| Database | `database/schema.sql` |
| Runtime and setup | `Dockerfile`, `compose.yaml`, `compose.compat.yaml`, `docker/`, `scripts/` |
| Automated checks | `tests/` |
| Requirements and continuation | `docs/` |
