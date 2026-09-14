# Deploying Agentpro on cPanel (with Softaculous)

## Read this first: what Softaculous actually does

Softaculous installs a **brand-new, empty Laravel**. There is no button that
takes this repository and puts it on your server. What it is genuinely good at
is the tedious cPanel plumbing:

- creating a MySQL database, a user, and the grant between them
- putting a correct PHP version and a working Composer in place
- creating the app folder and a valid `.env` with a generated `APP_KEY`

So this guide uses it as a **scaffold**, then replaces the code it installed
with yours. If you are comfortable with SSH you can skip Softaculous entirely
and go straight to step 3 — it is fewer steps, not more. The rest of this
assumes you want the Softaculous route.

> **Nothing here is Laravel-version-specific except the PHP requirement.** If
> Softaculous offers a Laravel version, pick the newest; you are going to
> delete it anyway.

---

## 1. Check the host will actually run this

Do this **before you pay for hosting**. Three of these are not fixable after the
fact on a shared plan.

| Requirement | Where to check in cPanel | If it is missing |
|---|---|---|
| **PHP 8.2+** (8.3 preferred) | *Select PHP Version* / *MultiPHP Manager* | Dealbreaker |
| **MariaDB 10.6+ or MySQL 8.0+** | phpMyAdmin → SQL → `SELECT VERSION()` | **Dealbreaker.** Map search uses a `SPATIAL` index; MariaDB 10.4 hung on spatial DDL during development |
| Extensions `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `exif`, `gd`, `zip` | *Select PHP Version* → *Extensions* | Tick them. `gd` does all image processing — without it photo upload fails at runtime, not at boot |
| **Cron jobs** | *Cron Jobs* | Dealbreaker — see step 7 |
| SSH or cPanel *Terminal* | *Terminal* | Awkward but survivable — see the note in step 3 |
| `proc_open` / `exec` not disabled | *Select PHP Version* → check `disable_functions` | Composer will not run on the server; upload `vendor/` instead |
| Symlinks allowed | — | Needed for `storage:link`. Almost always fine |
| Free SSL (AutoSSL / Let's Encrypt) | *SSL/TLS Status* | Needed. Web push and service workers require HTTPS |

FFmpeg will not be available, and that is fine: uploaded video is stored intact
but not transcoded, the asset stays `pending`, and the job can be replayed later.
Nothing is lost.

---

## 2. Run the Softaculous installer

cPanel → **Softaculous Apps Installer** → search **Laravel** → *Install*.

| Field | What to put |
|---|---|
| Choose Protocol | `https://` (with `www.` or without — pick one and stay with it) |
| Choose Domain | your domain |
| **In Directory** | **Leave this blank.** |
| Database Name | e.g. `agentpro` — note the full name it creates, like `cpuser_agentpro` |
| Database User | note the full name and the password |

> **"In Directory" must be empty.** The app serves `/storage/...` for media and
> registers a service worker at `/sw.js`, both root-absolute. Installed under
> `example.com/app/` those resolve to the wrong place and you get a site with no
> photographs and no push. Use a subdomain if you need a staging copy.

When it finishes, note the install path — usually `/home/<cpanel-user>/laravel`
or similar. Softaculous also leaves an `index.php` in that folder that redirects
to `public/`. Step 5 removes the need for it.

---

## 3. Put the real application in

Over SSH or cPanel **Terminal**:

```bash
cd ~
rm -rf agentpro-new && git clone <your-repo-url> agentpro-new
```

Keep the `.env` Softaculous generated — it has a valid `APP_KEY`:

```bash
cp ~/laravel/.env ~/agentpro-new/.env
```

Then install dependencies:

```bash
cd ~/agentpro-new && composer install --no-dev --optimize-autoloader
```

> **No Node, no npm, no build step.** The CSS is hand-written and served from
> `public/css/app.css`. `package.json` exists for tooling that this app does not
> use at runtime — ignore it.

> **If Composer will not run on the server** (`proc_open` disabled), run
> `composer install --no-dev --optimize-autoloader` on your own machine and
> upload the `vendor/` folder along with everything else. It is a few thousand
> files, so zip it, upload the zip, and extract with cPanel's File Manager.

> **Do not upload `public/hot`.** It is a local development marker. It is
> already in `.gitignore`, so a clone will not have it — only a drag-and-drop
> upload of your working folder would.

Swap the folders:

```bash
mv ~/laravel ~/laravel-old && mv ~/agentpro-new ~/agentpro
```

---

## 4. Fill in `.env`

```bash
nano ~/agentpro/.env
```

The lines that matter:

```ini
APP_NAME=Agentpro
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=cpuser_agentpro
DB_USERNAME=cpuser_agentpro
DB_PASSWORD=the-password-you-noted

# Both must be database-backed. 'sync' would run every notification and every
# export inside the web request that triggered it.
QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database

MAIL_MAILER=smtp
MAIL_HOST=mail.your-domain.com
MAIL_PORT=587
MAIL_USERNAME=no-reply@your-domain.com
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS="no-reply@your-domain.com"
MAIL_FROM_NAME="Agentpro"

# Live payments. Without a key a production environment refuses to start a
# payment at all, rather than pretending to take one.
PAYSTACK_SECRET_KEY=sk_live_...
PAYSTACK_PUBLIC_KEY=pk_live_...
```

`APP_DEBUG=false` is not a style preference. With it on, any error page prints
your database password and every other environment variable to whoever
triggered it.

Leave `APP_KEY` exactly as Softaculous generated it. Changing it later logs
everyone out and makes existing encrypted columns unreadable.

---

## 5. Point the domain at `public/`

This is the step people skip, and skipping it publishes `.env` to the internet.

cPanel → **Domains** → your domain → *Manage* → set **Document Root** to:

```
/home/<cpanel-user>/agentpro/public
```

Then delete the redirect stub Softaculous left behind:

```bash
rm -f ~/agentpro/index.php
```

> **If your host will not let you change the document root**, the fallback is a
> `.htaccess` in `public_html` that rewrites everything into the app's `public`
> folder. It works, but every file outside `public/` is then one
> misconfiguration away from being downloadable. Prefer changing the document
> root; consider a host that allows it.

---

## 6. Database, storage, permissions

```bash
cd ~/agentpro

php artisan migrate --force
php artisan storage:link
```

> ### Never run the seeder on a live site
>
> `db:seed` creates six accounts whose password is the word `password`, two of
> them full administrators. `--force` gets past the production prompt, so it is
> not what protects you. The seeder now refuses to run when `APP_ENV=production`
> — do not work around it. Run `migrate --force` **on its own**.

`storage:link` is not optional: without it every uploaded photograph has no
public path and listings fall back to placeholder artwork.

Permissions, if your host has not set them already:

```bash
chmod -R 755 ~/agentpro/storage ~/agentpro/bootstrap/cache
```

Then cache the configuration:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Re-run those three **every time** you change `.env` or deploy. A cached config
ignores later edits to `.env`, which produces the maddening bug where the file
is obviously right and the app obviously disagrees.

---

## 7. The two cron jobs

**The app is not finished without these.** Without the queue worker nothing is
ever emailed or notified. Without the scheduler, listing alerts accumulate in
their batching window and never close, saved searches never run, account
erasures never execute, and settlements are never reconciled.

First find the right PHP binary — cPanel's default `php` is often an old
version:

```bash
which php
ls /usr/local/bin/ea-php*
```

cPanel → **Cron Jobs** → add both, *Once Per Minute* (`* * * * *`):

**The scheduler**

```
cd /home/<cpanel-user>/agentpro && /usr/local/bin/ea-php83 artisan schedule:run >> /dev/null 2>&1
```

**The queue worker**

```
/usr/local/bin/ea-php83 /home/<cpanel-user>/agentpro/artisan queue:work --stop-when-empty --max-time=55 --tries=3 >> /dev/null 2>&1
```

`--stop-when-empty --max-time=55` is what makes a long-running worker safe on a
cron schedule: it drains the queue, then exits before the next minute's run
starts. Shared hosting has no Supervisor, and a plain `queue:work` would be
killed by the process watchdog and leave jobs half-done.

---

## 8. First administrator

The seeder will not run, so the site has no staff account. Create it properly:

1. Visit `https://your-domain.com/register` and sign up normally.
2. Then, over SSH:

```bash
php artisan agentpro:make-admin you@your-domain.com --role=admin
```

Roles: `admin` (everything), `moderator` (listings, no money),
`realsure_officer` (the badge console), `technician` (capture assignments).
`--revoke` removes access. Every grant is written to the audit trail.

Until somebody has `moderator` or `admin`, **no listing can ever be published** —
approval is the only path to public visibility.

---

## 9. The things that only start working on a real domain

Two features were undemonstrable in local development and should be switched on
and checked here:

**Web push (FR-M9-08).** Service workers require HTTPS, which is why this never
ran locally. Generate a key pair, add it to `.env`, re-cache the config:

```bash
php artisan agentpro:push-keys
```

Leaving the keys blank is a supported state — push falls back to logging what it
would have sent, and email and the in-app inbox still work.

**Paystack webhooks (FR-M4-04).** In the Paystack dashboard, set the webhook URL
to:

```
https://your-domain.com/webhooks/paystack
```

It must not sit behind password protection or a "coming soon" plugin. Payment
confirmation arrives on this URL, not on the customer's return trip — a blocked
webhook means people are charged and their order never completes.

---

## 10. Check it worked

| Check | Expected |
|---|---|
| `https://your-domain.com/up` | `200`, Laravel's health endpoint |
| `https://your-domain.com/.env` | **404 or 403.** If this downloads a file, stop and fix step 5 |
| Home page | Loads, with the Lagos banner photograph |
| `/search` | Map draws, tiles load, pins appear |
| Register, then upload a listing photo | Image appears — proves `gd` and `storage:link` |
| `/sitemap.xml` | XML, not an error |
| Trigger any email, wait a minute | Arrives — proves the queue cron |
| `storage/logs/laravel.log` | No stack traces |

---

## Deploying an update later

```bash
cd ~/agentpro
php artisan down

git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache

php artisan up
```

Back up the database before any deployment that carries a migration. cPanel →
*Backup* → *Download a MySQL Database Backup* takes about ten seconds and is the
difference between an annoying evening and a catastrophic one.

---

## When something is broken

| Symptom | Cause |
|---|---|
| 500 on every page | Check `storage/logs/laravel.log`. Usually permissions on `storage/`, or a missing `APP_KEY` |
| Laravel's directory listing, or `.env` downloads | Document root is not `public/` — step 5 |
| Photographs are grey placeholders | `storage:link` was not run, or `gd` is not enabled |
| Photo upload fails but everything else works | `gd` or `exif` missing, or `upload_max_filesize` too small in *MultiPHP INI Editor* |
| `.env` edits have no effect | Config is cached. Re-run `php artisan config:cache` |
| Nothing is ever emailed | The queue cron is not running, or `QUEUE_CONNECTION` is not `database` |
| Alerts pile up and never send | The scheduler cron is not running |
| Map pane blank | Check the browser console. Outbound requests to `tile.openstreetmap.org` may be blocked |
| Payments taken, orders never complete | The Paystack webhook cannot reach `/webhooks/paystack` |
| `SQLSTATE[42000] ... SPATIAL` during migrate | MySQL/MariaDB too old — step 1 |

---

## A note on where this ends

Shared hosting with cron-driven queues is a legitimate way to launch cheaply,
and it is a deliberate fit with how this project was built — PHP and MySQL,
server-rendered, no build step, precisely so that deployment and maintenance
stay cheap.

It has a ceiling, and it is worth knowing where. The queue is a minute granular,
so notifications arrive up to a minute late. Uploaded media lives on the same
disk as the application, so it is bounded by your hosting quota and is not
backed up independently — `AGENTPRO_MEDIA_DISK=s3` moves it when that matters.
There is no zero-downtime deploy; `artisan down` is the whole story. Video is
never transcoded without FFmpeg.

None of that blocks a launch. All of it is a reason to move to a small VPS once
there is real traffic, at which point the only things that change are Supervisor
instead of a cron for the queue, and Nginx instead of cPanel.
