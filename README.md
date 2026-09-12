# Agentpro

Verified property marketplace for Lagos and Abuja. Server-rendered PHP/MariaDB —
Laravel 12, Blade, plain CSS. No SPA, no front-end build step required to run.

Design direction follows the approved RealPress skin (with the three WCAG
corrections from PRD §15). Information architecture follows HotPads: map-first
search, saved-search alerts, a property/unit inventory model.

---

## Requirements

| | Version | Notes |
|---|---|---|
| PHP | 8.2+ | 8.3 recommended for production |
| MariaDB | **10.11 LTS** | the agreed target (PRD Q20). MySQL 8.0+ also works — see *Database engine* |
| Composer | 2.x | installed per machine — not vendored in the repo |

Extensions: `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `gd` (image
processing), `zip`.

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Create the database, then:

```bash
php artisan migrate --seed
php artisan serve
```

The seed loads development inventory across the ten 3D coverage areas, because
the map-first search looks broken on an empty city (PRD risk R9).

Seeded accounts — all password `password`:

| Email | Role |
|---|---|
| `tunde@example.test` | Verified seller's agent |
| `ngozi@example.test` | Verified developer |
| `realsure@example.test` | RealSure officer (staff) |

---

## Database engine

The schema uses a `POINT` column with a `SPATIAL` index for viewport search.
That index is what keeps map search affordable without adding a search cluster.

The DDL is deliberately portable: it omits MySQL 8's `SRID 4326` column
attribute, which **MariaDB rejects outright**. Coordinates are stored as plain
cartesian `POINT(lng, lat)`; distance uses `ST_Distance_Sphere`, which both
engines implement and which returns metres regardless of SRID. `lat`/`lng`
decimals are kept alongside for the map pane and as a portable fallback.

> **XAMPP's "MySQL" module is MariaDB.** The control panel labels it MySQL, but
> `mysqld --version` reports `10.4.32-MariaDB`. This is why the schema avoids
> MySQL-8-only syntax.
>
> **Observed on 10.4.32 during initial setup:** a `DROP TABLE` against an
> InnoDB table carrying a `SPATIAL` index hung indefinitely and wedged all
> subsequent DDL — every `CREATE TABLE` blocked, the stuck threads ignored
> `KILL`, and a graceful shutdown could not complete. It needed a force-kill and
> restart. This happened once and has not been reproduced or traced to a
> specific upstream bug, so treat it as a caution rather than a known defect: if
> `migrate:fresh` hangs, check `SHOW PROCESSLIST` for threads stuck in
> `Creating table` and restart the service.
>
> MariaDB is a fine production engine. The question is the **version**, not the
> fork — 10.4 reached end of life in June 2024 and receives no further fixes.

**Decided (PRD Q20): MariaDB 10.11 LTS, in development and production.** It keeps
the XAMPP workflow at no running cost and is supported to 2028. XAMPP 8.2.12
bundles 10.4.32, so the bundled server needs replacing — take a full
`mysqldump --all-databases` first, since a shared XAMPP instance holds other
projects' data too.

## Architecture

```
app/
  Enums/          LifecycleState, MediaKind, PricePeriod
  Models/         Property, Unit, FeeLine, TitleClaim, MediaAsset, RealsureRecord…
  Queries/        PropertySearch — the single source of "what can this person see"
  Support/        Money (naira formatting), Vocab (controlled vocabularies)
resources/views/
  components/     property-card, fee-panel, media-viewer, placeholder, icon
  pages/          home, search, show
public/css/app.css  design tokens + components
```

**Property → Unit.** Shared attributes (address, location, title, media,
amenities) live on the property; anything that can differ between two flats in
the same block (price, bedrooms, floor, availability) lives on the unit. A single
dwelling is a property with exactly one unit, so every query has one shape.

**`PropertySearch` is the only place that decides visibility.** The search page,
the map pin endpoint, the sitemap and the saved-search matcher all route through
it. If they diverged, a listing could appear on the map and 404 on click, or stay
visible to alerts after being unpublished.

## Conventions

- Public URLs key on `uuid`, never the sequential id (SEC-10).
- Prices are read server-side from `config/agentpro.php`. The client never sends
  an amount (SEC-05).
- Rich media never autoplays or preloads — it is poster-gated and loads on tap
  (NFR-02). A seeker on a metered connection must be able to read a full listing
  without spending a naira on media they did not request.
- Fee tables and legal text are set in Inter, not Quicksand (PRD Q16).
- `audit_events` is append-only. Nothing deletes from it.

## Tests

```bash
php artisan test
```

Tests run against a real `agentpro_test` database (see `phpunit.xml`), not
sqlite — the schema uses spatial DDL that sqlite cannot build. Coverage is
deliberately narrow: the money and trust paths only, per PRD §17.

`tests/Feature/ListingSubmissionTest.php` covers the rules the product's
credibility rests on — an unverified lister cannot submit, a listing with no
cost breakdown cannot submit, one lister cannot touch another's draft, a draft
404s publicly, closed listings are excluded from default search, the move-in
total sums correctly, and audit events cannot be altered.

## Still to build

Not yet implemented:

- Identity-verification vendor integration — the interface and a dev stub exist
  (`app/Services/Identity/`), the real driver is bound in `AppServiceProvider`
  once PRD Q1 is settled
- Media upload and the transcode queue (M3). Photos are currently attached
  directly in the database for development
- Admin console and moderation queue — Filament (M12). Listings currently reach
  `submitted` and stop there, since nothing consumes the review queue yet
- Paystack checkout, webhooks and the scan scheduling workflow (M4, M11)
- Real map library in place of the SVG mock; `/search/pins` already returns the
  production payload
- Media upload, transcode queue and the moderation gate (M3)
- Saved-search matcher and notification fan-out (M5, M9)

## Open decisions blocking build

See PRD §13. Closed: **Q15** (Property→Unit — implemented here) and **Q20**
(engine — MariaDB 10.11 LTS). Still open and touching this codebase:

- **Q18** — video hosting: self-host on CDN, or embed Vimeo/YouTube. Changes
  `media.video_strategy` in `config/agentpro.php` and the CSP.
- **Q14** — sign-off on the three corrected palette values in `public/css/app.css`.
- **Q1** — identity vendor (VerifyMe.ng vs SmileID). Stubbed behind an interface
  so the choice does not block the auth build.
- **Q5** — rent only, sale only, or both at launch; and whether shortlets are in
  scope. `price_period` already carries `night` for that case.
