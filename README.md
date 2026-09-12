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
| MariaDB | **10.11.19** | installed. MySQL 8.0+ also works — see *Database engine* |
| Composer | 2.x | installed per machine — not vendored in the repo |

Extensions: `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `exif`, `gd`, `zip`.

> XAMPP ships `gd` and `zip` **commented out** in `php.ini`. Both are required —
> `gd` does all image processing, and without it photo upload fails at runtime
> rather than at boot. Uncomment `extension=gd` and `extension=zip`, then
> restart PHP. A backup of the original is at `php.ini.bak-agentpro`.

**Paystack** is optional locally. With `PAYSTACK_SECRET_KEY` set — their test
keys are fine — the real integration runs in any environment. Without it, local
and testing fall back to a fake gateway that signs webhooks with the same
HMAC-SHA512 scheme, so signature verification is exercised for real rather than
bypassed; a non-local environment with no key refuses to start a payment at all.
The fake does not mark anything paid on its own: `/orders/{order}/sandbox` shows
the signed webhook body and the `curl` to send it, so the real
pay → webhook → confirm sequence is what gets tested.

**Notifications and saved searches** need a queue worker
(`php artisan queue:work`) and the scheduler (`php artisan schedule:work`) —
the scheduler closes batched listing-alert windows and runs saved searches
(every fifteen minutes for `instant`, 08:00 for `daily`). Without the
worker nothing is sent; without the scheduler, alerts accumulate in their window
and never close. Mail goes to `storage/logs/laravel.log` under the default
`MAIL_MAILER=log`. WhatsApp logs what it would send until a Meta business
account and approved templates exist.

**FFmpeg is optional but recommended.** Without it, uploaded video is stored
intact but never transcoded: the asset stays `pending`, the job logs
`media.video.transcode_unavailable`, and nothing is lost — the job can be
replayed once FFmpeg is installed. Point `FFMPEG_PATH` / `FFPROBE_PATH` at the
binaries, or put them on `PATH`.

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
| `realsure@example.test` | Admin — the whole console |
| `finance@example.test` | Admin — a second one, so a large refund can be approved |
| `technician@example.test` | Capture technician |

Two admins rather than one is deliberate: a refund over
`agentpro.refunds.dual_approval_above` cannot be approved by the person who
asked for it, so with a single account that path is unreachable. The seed leaves
one waiting, which `finance@example.test` can approve from **Orders**.

Settlements come from the development gateway, which derives them from the
orders actually in the database — so the reconciliation screen shows a real,
balanced picture rather than invented money. Pull them with:

```bash
php artisan agentpro:reconcile-settlements
```

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
> the binary reports MariaDB. This is why the schema avoids MySQL-8-only syntax.

**Decided (PRD Q20): MariaDB 10.11 LTS, in development and production** —
supported to 2028, and it keeps the XAMPP workflow at no running cost.

### The upgrade, as performed

XAMPP 8.2.12 bundles MariaDB **10.4.32**, which reached end of life in June 2024.
On 10.4.32 a `DROP TABLE` against an InnoDB table carrying a `SPATIAL` index hung
indefinitely and wedged all subsequent DDL — every `CREATE TABLE` blocked, the
stuck threads ignored `KILL`, and graceful shutdown could not complete. It needed
a force-kill. `migrate:fresh` was effectively unusable.

The bundled server was replaced with **10.11.19** (official ZIP, SHA256 verified)
using a fresh data directory and a dump/restore rather than an in-place data-file
upgrade — the dump is version-neutral SQL, which sidesteps data-format risk.

Measured before and after, same schema, same machine:

| | 10.4.32 | 10.11.19 |
|---|---|---|
| `migrate:fresh --seed` | hung indefinitely, force-kill required | **5s** |
| create + spatial index + drop | wedged the server | **0.14s**, repeatable |
| viewport query plan at 20k rows | `range`, 430 rows examined | `range`, **13 rows** examined |

InnoDB sizing was also raised from XAMPP's defaults (16M pool / 5M log) to
256M / 128M, which are sane for a development database carrying spatial indexes.

The previous install is preserved at `C:\xampp\mysql-10.4-backup` (285 MB) and
dumps are in `storage/backups/`. Delete the backup folder once you are satisfied
everything works.

## Map

Leaflet 1.9.4, vendored in `public/vendor/leaflet/`. Raster tiles do not need a
WebGL renderer, so this is 145KB rather than MapLibre's 918KB on a page with a
1.2MB budget (NFR-02), and it works on devices without WebGL — the device class
NFR-01 is written for. Vendored rather than CDN-loaded because search is the
product's front door and should not stop working because a third party is
unreachable.

Markers are aggregated **in SQL**, not in the browser: `/search/pins` returns
clusters below zoom 14 and individual price pins above it, so a city-wide
viewport sends a few dozen rows instead of several thousand. Panning swaps the
result list as an HTML fragment rather than re-rendering the page, and the URL
is kept in step so a panned view is shareable and survives a reload.

> **The default tile source is not production-ready.** It points at
> OpenStreetMap's own raster service, whose usage policy prohibits heavy or
> commercial use — they are entitled to block traffic that ignores it. Before
> launch set `AGENTPRO_TILE_URL` to a provider with a contract (MapTiler,
> Stadia, or self-hosted Protomaps) and update `AGENTPRO_TILE_ATTRIBUTION` to
> match.

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
the map pin endpoint and the saved-search matcher all route through it, and
saved criteria are validated by its own rules. If they diverged, a seeker could
be alerted about a listing that 404s when they click it — or one they are not
allowed to see, since visibility is decided in that same query.

**Money is confirmed by the provider, never asserted locally.** An order becomes
paid when a verified transaction says so, and a refund becomes `processed` when
the provider says the money landed — not when an operator pressed the button.
Between those two points a refund sits in `submitted` and the order still reads
`paid`, because it is still true. `refunds` carries that lifecycle; `settlements`
and `settlement_transactions` carry the provider's side of it; and reconciliation
is what compares the two.

**Refunds need two people over a ceiling.** A refund travels back along the
transaction that paid it, so a stolen admin session cannot use it to take money
out of the business — but it can destroy revenue. Under
`agentpro.refunds.dual_approval_above` an admin refunds directly; over it, a
*different* admin has to approve. Moderators cannot refund at all.

**Saved searches track reported listings, not a timestamp.** A listing published
last week at ₦15M that drops to ₦11M today becomes a match without its
`published_at` moving, so a watermark would never report it — and a price drop
into range is the most valuable alert this feature sends. `saved_search_matches`
records what has been reported, which also makes de-duplication exact across an
unpublish/republish.

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

`ListingSubmissionTest` covers the rules the product's credibility rests on — an
unverified lister cannot submit, a listing with no cost breakdown cannot submit,
one lister cannot touch another's draft, a draft 404s publicly, closed listings
are excluded from default search, the move-in total sums correctly, and audit
events cannot be altered.

`MapSearchTest` covers what the map depends on server-side — a zoomed-out
viewport returns aggregated clusters rather than a row per listing, zooming in
returns individual pins, the viewport and filters both apply to markers, drafts
never appear on the map, and the result list can be fetched as a fragment
without page chrome.

`MediaUploadTest` covers the upload pipeline — a disguised file is rejected by
content sniffing, a real EXIF segment is spliced into the fixture and proven gone
from every rendition, the responsive set is written, the difference hash matches
the same photograph at another size and separates unrelated ones, and pending
video stays off the public listing.

`ScanPurchaseTest` covers the only revenue path in R1, concentrating on the ways
money and delivery come apart — an ineligible property cannot reach checkout,
the price comes from configuration and not the request, returning from the
gateway confirms nothing, a bad signature is rejected and recorded, a replayed
webhook is a no-op, a short or failed transaction never credits the order, a
full slot cannot be double-booked, and a paid-but-unbooked capture is surfaced
rather than lost.

`SavedSearchTest` covers the retention loop — existing matches are baselined
rather than blasted out on the first run, a listing published afterwards is
reported, **a price drop into range is reported** (the case a timestamp
watermark cannot see), nothing is reported twice even across an
unpublish/republish, drafts never leak, and a lister is not alerted about their
own listing.

`NotificationTest` covers who gets told what — quiet hours including the
overnight window that wraps past midnight, WhatsApp and SMS staying off unless
chosen, only interacting users being alerted (never the lister, never someone
who hid the listing), several changes batching into one message, cosmetic edits
queueing nothing, every email carrying a way out, and every channel a
notification can route to being resolvable from the container.

`RefundTest` and `ReconciliationTest` cover the money that moves after a sale.
Almost every case is a disagreement, because agreement is not what reconciliation
is for: a payout containing money no order accounts for, an order marked paid
that no payout ever contained, and a transaction list that came back short —
which must read as "could not check this settlement", never as a clean run. The
refund tests pin down that submitting is not paying back, that a refund still in
flight is already spoken for so two operators cannot refund the same money, that
a failure at the provider leaves a visible failed record rather than vanishing,
and that nobody approves their own large refund.

`ModerationTest` covers the gate between submission and the public — the console
is invisible to non-staff, approval stamps the display period from the decision
(not the submission), a rejection cannot be saved without an actionable note,
unpublishing removes a live listing, every decision is attributed on the audit
trail, and duplicates are flagged rather than blocked.

## Still to build

Not yet implemented:

- Identity-verification vendor integration — the interface and a dev stub exist
  (`app/Services/Identity/`), the real driver is bound in `AppServiceProvider`
  once PRD Q1 is settled
- Video transcoding in practice — the job is written and dispatched, but needs
  FFmpeg installed to produce the 720p/480p renditions and the poster frame
- The rest of the admin console beyond moderation — user and KYC administration,
  taxonomies, coverage areas, technician roster. **Filament** is the intended
  tool for this routine CRUD. The moderation queue was deliberately hand-rolled
  instead: FR-M12-02 wants a purpose-built side-by-side review view (content,
  media, declared title, fee breakdown, duplicate flags on one screen), which
  generic CRUD scaffolding does poorly
- Web push and SMS transports. Both are declared as channels and currently fall
  back to the in-app inbox rather than failing
- Payouts to listers. Reconciliation covers money coming *in*; there is no
  disbursement side, because R1 has nothing to disburse

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
