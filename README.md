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
php artisan storage:link
```

`storage:link` is not optional — without it the seeded photographs have no
public path and every listing falls back to placeholder artwork.

Create the database, then:

```bash
php artisan migrate --seed
php artisan serve
```

The seed loads development inventory across the ten 3D coverage areas, because
the map-first search looks broken on an empty city (PRD risk R9). The first run
downloads about 5MB of photographs and takes a minute or two; they are cached
under `storage/app/seed-photos`, so later runs are quick. With no network it
falls back to placeholder artwork and still finishes.

Seeded accounts — all password `password`:

| Email | Role |
|---|---|
| `tunde@example.test` | Verified seller's agent |
| `ngozi@example.test` | Verified developer |
| `realsure@example.test` | Admin — the whole console |
| `officer@example.test` | RealSure Officer — only the badge console, to show the role gate working |
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

## Notifications

Email and the in-app inbox work out of the box. The other three need setting up:

```bash
php artisan agentpro:push-keys     # prints a VAPID pair for .env
```

Leave `VAPID_PUBLIC_KEY` blank and push logs what it would have sent instead of
failing — a missing key costs a convenience, not an outage, and the inbox and
email still arrive. Replacing a live pair invalidates every existing
subscription, because browsers subscribe to a specific application server key.

SMS is `SMS_DRIVER=log` by default. Set it to `termii` with a key and a sender
ID registered with Termii to send for real. WhatsApp logs until there is a Meta
business account with approved templates.

Payouts need Paystack's Transfers API enabled on the integration, and its
per-transfer OTP turned off — nothing here can read a one-time code, so a
transfer that comes back `otp` is logged and left for a person rather than
treated as pending.

**The service worker does not register under `php artisan serve` on Windows.**
The built-in PHP server is single-threaded — six concurrent requests to it here
were served strictly one at a time — and registering a worker needs a second
connection while the page still holds the first, so the browser reports "an
unknown error occurred when fetching the script". Nothing is wrong with the
script or the route; serve the app through Apache, Nginx, or any multi-worker
server and registration works. Everything else on the push path (encryption,
signing, storage, pruning) is exercised by the test suite and does not depend on
this.

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

**Payouts are the only money with a destination somebody chose.** Everything
else moves into the business or back along the transaction that brought it in,
where the worst a stolen admin session can do is give money back to the people
who paid it. A payout goes wherever the account details say, which invents an
attack the rest of the system does not have: take over a lister's login, change
the bank details, withdraw. Three controls answer it, and they are the feature —
the transfer itself is one API call.

The account name comes from the bank's resolve endpoint, never from the form; a
new or changed account is held for `payouts.account_hold_hours` before anything
can be sent to it, with a warning to the contact details *already on file*; and
a bank name that does not match the verified identity waits for a person. Every
payout needs a second admin with no threshold that skips it, unlike refunds.

**What is owed is a ledger, not a column.** A stored balance drifts, and
afterwards there is no way to say which number was right or where the difference
came from. `App\Support\Ledger` sums append-only entries — a correction is
another entry, never an edit — so a lister's statement explains itself line by
line. Money comes off the ledger when a payout is *requested*, not when it is
sent, or two requests can each be for the whole balance.

One deliberate asymmetry: a failed *webhook* returns the money automatically, but
a transfer call that **throws** does not. That call may have reached the provider
before it failed, so crediting the balance could pay the same money twice. It
goes back when a person has established what actually happened.

**A taxonomy slug is a foreign key held in other people's data.** Amenities and
areas are filtered by slug, not id — `PropertySearch` does
`where('slug', $slug)` — and a saved search stores the criteria it was built
from, slugs included. So renaming a slug in place breaks nothing loudly and
every saved search quietly: they still run and return a different set of
results from the one the seeker asked for. `RetagTaxonomy` owns every such
change and rewrites the references in the same transaction. It is also why
merging exists and why deleting a term in use is refused: the pivot cascades, so
deleting an amenity would strip it from every listing carrying it with no error
and no record of which ones.

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

**Push is encrypted to the browser, not to us.** Web push has no vendor and no
account: the browser hands over an endpoint that already names its own push
service, and one signed request works against Google, Mozilla and Microsoft.
The body is encrypted with a key only that browser holds (RFC 8291), which is
why `PushPayload` cannot be simplified away — and why it is tested against the
RFC's published vector rather than by round-tripping itself. Getting the key
schedule wrong does not throw and does not fail the request: the push service
returns 201 and the browser silently discards a message it cannot read.

No `minishlink/web-push`. It wants `ext-gmp`, which is not in a stock XAMPP, and
adding an extension requirement to the deployment is the cost this project was
set up to avoid. Core PHP has everything: openssl for P-256 and AES-GCM,
`hash_hkdf` for the key schedule.

**SMS is billed per segment, and the boundary is not where anyone expects.**
Plain ASCII gets 160 characters; one character outside GSM 03.38 — a pasted
curly apostrophe, an en dash, a ₦ sign — re-encodes the whole message and drops
the allowance to 70. `SmsText` normalises the copy before measuring it, so
"₦7,500,000 — Ikoyi" costs one segment rather than three. On Nigerian networks
there is a second constraint: subscribers opt out of promotional traffic at the
network (DND), so anything sent on the ordinary route to them is accepted,
billed and never delivered. Transactional messages go on a separate cleared
route, and using it for marketing is what gets a sender ID banned — which is why
saved-search alerts deliberately have no `toSms()` at all.

**HotPads parity on the listing page (PRD §16).** Four R1 items from that
benchmark lived only on the server: saving, hiding and reporting a listing each
had a route, an action and passing tests, while the page a seeker would use them
on had three `<button type="button">` elements wired to nothing. Price history
was eager-loaded and never rendered. That is the failure mode where every unit
test passes and the feature does not exist, which is why `ListingDetailTest`
asserts the page *reaches* the behaviour rather than that the behaviour works.

The page now carries the same signals HotPads leads with. **"Updated N ago"**
sits beside the address, where a seeker decides whether a listing is still real
(FR-M2-14) — it was on the card and missing from the detail page.
**Price history** (FR-M7-07) shows only once the price has actually moved; one
point is not a history and a single-row panel implies a change that did not
happen. **Interest this week** is HotPads' "Competition for this rental", built
from events M13 already records, and it is the same figure the lister sees on
their performance screen so the two cannot tell different stories about one
listing. It is suppressed below `listings.demand_floor`: "viewed 2 times this
week" reads as a dead listing whether or not it is one, and at launch — when
supply is deliberately thin because every listing is human-approved — publishing
that about somebody's property helps nobody.

**The listing has its own map**, reusing the same Leaflet build as search rather
than a second map stack. Scroll-wheel zoom is off and one-finger drag is
disabled on touch, because a map halfway down a long page that swallows the
scroll gesture is a map you cannot scroll past. The marker is a soft circle
rather than a dropped pin: the coordinate is what the lister typed, and in a
market where street addressing is unreliable (problem P3) a sharp pin claims a
precision nobody has verified.

The search map needed no change — it is already the primary surface, and on
mobile it carries `order:-1` so it sits above the results rather than below them.

**The corporate pages are built from the database, not from copy.** An area page
that says "Lekki Phase 1 is a vibrant neighbourhood" tells a seeker nothing they
can act on, and goes stale the moment it is written; one that says how many
listings are live, what the middle of the price range is and whether 3D capture
is available there is worth opening and cannot drift. The same rule holds for
the agent directory, which lists only verified listers who have stock, and for
the lister profile (FR-M1-07), where every figure is derived and nothing is
written by the lister. A rating is withheld below `profiles.minimum_ratings`,
because a "5.0" from one rating is not a reputation and printing it as one would
mislead in the lister's favour — the opposite of what a trust platform is for.

**The privacy notice is generated from `PersonalData::map()`** — the same
manifest the erasure runs on. A notice written by hand starts accurate and
drifts the first time a table is added; this one cannot, because the guard test
that forces every new table into the manifest is also what keeps the page
complete. The prose around it still needs a Nigerian lawyer; what it says about
the system is true, which is the part software can be responsible for.

**The RealSure officer console moved to `/officer`.** The product name belongs
to the public page the header and footer link to, and an internal console should
never hold a URL the marketing surface needs. Route names stay `realsure.*`
because they describe the records being managed, while the path describes who
the screens are for — the same split as `/technician`.

**The RealSure badge is granted, never derived.** It would be easy to light it
up the moment a component is ticked, and wrong: the ten components in FR-M6-02
include photography and floor plans, which are services the lister bought
rather than anything that was checked, so a badge earned by a drone flight
would say "verified" about a listing nobody verified. `Vocab` splits the ten
into verification and production for exactly that one decision; only the first
group counts towards the badge, and title verification is mandatory because the
standing disclaimer under every listing names that component specifically — a
badge granted without it would contradict the sentence printed beneath it.
Granting is a separate, deliberate act by an officer, recorded in the audit log
with the components it rested on.

It also comes off the same way it goes on. A revocation needs a reason, the
lister is told (they paid for it), and withdrawing a verification check that a
granted badge depended on revokes the badge automatically — leaving that to
whoever remembers is how a listing ends up asserting something nobody stands
behind. The officer console lives at `/realsure` under its own
`staff:realsure_officer` gate rather than inside `/admin`, because deciding
whether a listing may be published and deciding what Agentpro is willing to
assert about it are different powers that should not imply one another.

**The public badge panel lists all ten components, not the recorded ones.** An
officer records what they did; there is no reason for them to create a row
saying "we did not commission a valuation". But to a seeker the absence of a
record and an explicit "not done" are the same fact, and rendering only the
completed rows would turn the panel into a list of ticks that reads as a full
audit. A badge that does not say what was checked is worth nothing, and one
that hides what was *not* checked is worse than nothing.

**The seed loads real photographs, through the real upload pipeline.** Grey
placeholder rectangles tell you nothing about whether the media pipeline works,
whether the cards are the right shape, or whether nine photographs in a gallery
is too many. `SeedPhotos` fetches a curated set of Unsplash images (Unsplash
Licence — free use, no permission required; development fixtures, never shipped)
and hands each one to `StoreListingPhoto`, the same action a lister's upload
goes through. So the seed exercises content sniffing, the responsive WebP
renditions, EXIF stripping and the perceptual hash — it is the only fixture in
the project that produces a real file, and therefore the only one that can prove
that pipeline works.

The photographs are cached under `storage/app/seed-photos` (gitignored), fetched
once, and pinned by id so every run produces the same listing-to-photograph
mapping — a random image service would make two screenshots impossible to
compare. With no network the whole thing degrades to the placeholder artwork,
because a seeder that fails on a train is a seeder people stop running. Covers
come from a pool matched to the listing type, so a house leads with its exterior
and a flat with a room, and no two listings open on the same image.

**The public media disk builds relative URLs, not `APP_URL.'/storage'`.** With
the default, every photograph resolved to port 80 while the application was
served on 8000 — nothing displayed, and it went unnoticed for as long as the
seed produced only placeholders, which need no file at all. That disk is
development-only (production points `agentpro.media.disk` at S3), so its only
consumer is a browser rendering a page it already fetched from this host.

**Property status is a filter now (FR-M5-03).** The column has existed since
the first migration and drives 3D-capture eligibility, but it was never wired
to search — so there was no way to ask for off-plan or to exclude it, which in
this market is a different purchase entirely: different money, different risk,
different timeline. The filter key is `build_status` rather than the PRD's
wording "property status", because a filter key that differs from the column it
filters is a rename waiting to go wrong.

Two of that requirement's twelve filters are still missing: **tags** and
**title type**. Both are larger than this one — tags are partly derived, and
title type is a nineteen-value grouped vocabulary that needs real UI.

**The search split is sized by flex, not by guessing at the chrome.** It used
to be `calc(100vh - 66px - 60px)`, and the 60px was wrong: the filter bar wraps,
so it measures 105px at common widths and more when the chips run to three
lines. The split therefore ran about 45px past the bottom of the viewport and
took the map's OpenStreetMap credit with it, below the fold where nobody saw
it — a licence problem rather than a cosmetic one, since the ODbL requires the
credit to be visible. A `.searchpane` wrapper is now `calc(100dvh - var(--nav-h))`
with the split as `flex:1`, so only one chrome height is hard-coded and it is
the one that cannot change behind your back: the nav is a declared fixed height
whose links are hidden rather than wrapped on narrow screens. `MapSearchTest`
guards the wrapper, because removing it would bring the bug back silently.

While the consent banner is up it would sit on top of that same credit, so
Leaflet's bottom controls are lifted by `--consent-h` for as long as the banner
exists. "It reappears once you dismiss the banner" is not visible to the
first-time visitor who is the only person who ever sees the banner.

**The consent banner's two buttons are identical in weight, but that is about
emphasis, not visibility.** The first version got the principle right and the
execution wrong: the bar used `--surface` and so do ghost buttons, so the only
two controls on it had no fill contrast against the thing containing them and
read as faint outlines. The bar now sits on `--surface-2`, one step down the
ladder from cards, which gives the buttons an edge again and stops the bar
merging into a page made of cards. The shadow is a `--shadow-up` token because
both existing shadow tokens cast downward — invisible on a bar pinned to the
bottom — and because a hard-coded 7% black shadow does nothing on a near-black
page. The banner's only explanatory link pointed at `/account/data`, which sits
behind `auth`: the one link on a banner shown to guests bounced them to a login
wall. It goes to the public privacy notice, and a test pins that.

**Consent draws the line between counting and following.** FR-M13-04 says no
non-essential tracking before consent, and the difficult part is not the banner
— it is that a reading too broad leaves the lister's analytics permanently
empty, and one too narrow makes the banner a lie. The line here: counting that
an event happened, with nothing attached that could identify anyone, produces a
number, and a number is not personal data — so totals are recorded for
everyone, which is what keeps "viewed 340 times" true rather than
"340 times by the minority who accepted cookies". Following one person across
requests needs an identifier that persists, which is tracking on any honest
reading, so the visitor id is written only after an explicit yes and declining
actively clears it. Consent therefore does not switch analytics on and off; it
switches the visitor id on and off, and only journey-level analysis degrades.
Every report that depends on journeys states what share of traffic it could
see, because a conversion rate measured over consenting visitors alone is not
the site's conversion rate.

**The lister funnel is derived, not instrumented.** Register, verify, submit,
publish, upgrade are all already timestamps on `users`, `properties` and
`orders`, so recording them again would create a second version of the truth
that drifts the first time a code path writes one and not the other. It is
computed at read time in `app/Queries/Funnel.php`, which also makes it complete
for all time rather than only as far back as the event retention window, and
unaffected by consent — there is nothing to consent to in counting your own
customers. Only the seeker funnel needs recorded events, because none of its
steps leaves a trace anywhere else.

**A view counter is only worth having if a lister believes it**, so almost
everything in `app/Support/Analytics.php` is a subtraction: known crawlers
excluded, reloads collapsed to one view per session, drafts uncounted before
the 404, and map panning not mistaken for searching — the map refetches the
result list on every drag, and counting those would report one seeker who moved
the map twenty times as twenty searches. Recording also never throws: a listing
that 500s because a counter could not be written would be the measurement
destroying the thing it measured.

**Raw events are pruned, and the rollup is what allows it.** `analytics_daily`
exists as much for data minimisation as for speed — raw behavioural events are
the only rows describing what somebody did minute by minute, and they are kept
90 days. The rollup recomputes whole days rather than accumulating, so running
it twice produces the same numbers as running it once. Dwell is a median, not a
mean: one tab left open for an hour drags a mean past objective O2's 90-second
target on its own, and the resulting figure would say the tour is working when
nobody watched it.

**Erasure is anonymisation, not a delete, and `PersonalData` is where that is
decided.** Orders, refunds, payouts, ledger entries and the audit trail all hang
off the account row and all have to be kept — revenue and anti-money-laundering
rules require transaction records for six years, and s. 34(2) of the NDPA allows
for exactly that rather than overriding another statute. Dropping the row would
either cascade those away or orphan them, and an audit trail whose actor cannot
be resolved has stopped being one. So the row survives with every personal field
overwritten. `app/Support/PersonalData.php` records that judgement table by
table, with the justification we would have to give if a regulator asked, and
`PersonalDataTest` reads the live schema and fails on any table that references
`users` without an entry. That test is the real protection here: nobody is going
to break the erasure code, but somebody will add a table with a `user_id` on it
in eighteen months, and without the guard every privacy test would keep passing
while the platform quietly stopped honouring a statutory right on that table.

**An erasure waits, and is re-checked when it runs.** It is the most destructive
thing an account can do to itself and it cannot be undone, which makes it worth
something to whoever has stolen a login — not to steal, but to destroy. So it is
held for 72 hours and the warning goes to the contact details already on file,
the same control as a change of payout bank details, for the same reason. The
blockers — a live listing, a balance still owed, a payout or refund in flight, a
paid capture that has not happened — are then checked *again* at the moment it
runs, because three days is long enough for a listing to be republished, and
running anyway would destroy the record of an obligation that did not exist when
the button was pressed. Cancelling asks for nothing at all: it is the escape
hatch in a takeover, and an attacker who cancels their own erasure has achieved
nothing.

**An export is announced, not handed over.** The file is built on the queue and
the person is told through their registered contact details; the download itself
stays behind the session, and the message carries no link to it. A forwarded
email should not be a copy of somebody's whole account. Every query in
`ExportAccountData` names its columns explicitly rather than selecting `*`, so
the failure mode where a newly added column starts leaking by default cannot
happen — and a lister's export contains no detail of the seekers who enquired on
their listings, because that is the seekers' data and they have their own copy.

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

`PayoutTest` is almost entirely about the controls rather than the transfer: the
hold on new bank details, the name that comes from the bank and not the form,
nobody approving their own payout, the balance being spoken for at request time,
and the three different endings a transfer has — paid, failed, and reversed days
later, which has to put money back on a ledger that already spent it.

`ListingDetailTest` covers the HotPads parity items, and mostly checks that the
page can reach behaviour that already worked — saving, hiding and reporting were
tested end to end while the listing page wired to none of them. It also pins the
two judgement calls: price history appears only after the price has moved, and
the demand figure stays hidden below the floor.

`HomePageTest` holds one line: everything on the landing page is counted, not
claimed. A marketplace landing page is the easiest place in a product to start
overstating — "thousands of listings" when there are eight, a grid of areas that
lead to empty results, the same six properties under two headings to make the
inventory look twice the size — and each of those is a small lie a visitor can
check in one click.

`CorporatePagesTest` protects two things: that these pages exist at all — every
one of them was a dead `href="#"`, and one rotting back to nothing would be
worse than never having built them, so the suite fails if `href="#"` reappears
anywhere — and who appears on them. The directory, the profiles and the sitemap
all publish people, and an unverified lister must not reach any of the three.

`RealsureTest` is almost entirely about what must not be possible: a badge
earned on photography alone, a badge without the title check, a lister badging
their own listing, a badge that outlives the verification underneath it, and a
badge that cannot be taken off again. The console's own access is covered too —
it was built on top of a live bug where a RealSure Officer got a 404 on every
screen in the admin area, including the one named after their job.

`AnalyticsTest` is mostly about what must *not* be counted — crawlers, reloads,
drafts, map panning — because those are what make a view count believable, and
about holding the consent line in both directions: totals survive a refusal and
the visitor id does not. It also covers the two failure modes that would be
invisible otherwise: rolling up twice must not double the numbers, and a
dropped `analytics_events` table must not take the listing page down with it.

`PrivacyTest` divides unevenly on purpose. The export is mostly about what it
must *not* contain — another person's details, our own secrets, an unmasked
account number — because a right-of-access feature that over-shares turns a
privacy obligation into a data breach. The erasure is almost entirely about the
controls: that it waits, that it warns the person it is happening to on the
details already on file, that it refuses out loud while somebody is still owed
something, that cancelling needs nothing, and that the blockers are checked
again at the moment it runs rather than only when it was asked for.

`PersonalDataTest` guards the manifest against the schema, and then proves the
guard is not decoration by creating a table with a `user_id` on it and asserting
it gets caught.

`TaxonomyTest` is mostly about that: renaming a slug carries saved searches with
it and the rewritten search still returns its listing; merging keeps the
listings and handles the one tagged with both terms, which would otherwise
violate the pivot's composite key; an amenity in use and an area with listings
or capture slots are refused rather than silently detached.

`WebPushTest` leads with the RFC 8291 test vector, encrypted and decrypted, and
checks the VAPID token verifies against the key browsers are given and that its
audience is the endpoint's *origin* rather than the endpoint. It also covers the
operational half: a 410 prunes the subscription, a 503 does not, re-registering
the same endpoint does not duplicate it, and one account cannot unsubscribe
another's device.

`SmsTest` covers the two silent ways to waste money — a number that is billed
and undeliverable, and a message that costs three segments because of one
character nobody looked at — plus the rule that discovery notifications are
never sent by SMS.

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
- **Legal review of `/terms` and `/privacy`.** Both pages are built and both are
  accurate about what the system does — the privacy notice is generated from the
  erasure manifest, so it cannot describe something the code does not do. What
  neither has is a lawyer. Nothing on them invents a warranty, a liability cap
  or a dispute-resolution clause, because that is drafting rather than
  engineering, and a plausible-sounding invented clause on a live legal page is
  worse than a missing one. The company details they print
  (`agentpro.company.*`) are also empty until somebody fills in the real RC
  number and registered address.
- A real WhatsApp sender. The channel and template contract exist; it logs until
  there is a Meta business account with approved templates, which have to be
  submitted weeks before they can be sent
- **What earns a lister a payout.** The disbursement side is built and the ledger
  takes credits from anywhere, but R1 sells services *to* listers and collects no
  money on their behalf, so the only live source is an admin-granted credit —
  a launch or referral incentive (objective O4). If listings ever carry a
  commission, a booking deposit or rent collection, those become
  `Ledger::record(...)` calls at the point the obligation arises and nothing
  below them changes. **This is an open product decision, not a technical gap.**

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
