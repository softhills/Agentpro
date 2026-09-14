# Deploying Agentpro on shared hosting (cPanel or DirectAdmin, via Softaculous)

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

When it finishes, note where it actually installed. With **In Directory** blank
that is your document root — `/home/<cpanel-user>/public_html` — **not** a
folder named after the framework. Confirm rather than assume:

```bash
ls -la ~ && find ~ -maxdepth 2 -name ".env"
```

Softaculous also leaves an `index.php` there that redirects to `public/`. Step 5
removes the need for it.

---

## 3. Put the real application in

Over SSH or cPanel **Terminal**:

```bash
cd ~
rm -rf agentpro-new && git clone <your-repo-url> agentpro-new
```

Check which PHP you have before anything else — cPanel's default `php` is often
still 7.x, and every command below needs 8.2+:

```bash
php -v
```

If it is old, find the right one and use its full path in place of `php`
everywhere below, including in the cron jobs in step 7. Where to look depends on
the host:

```bash
which php; ls -d /usr/local/php8*/bin/php /usr/local/bin/ea-php* /opt/alt/php8*/usr/bin/php 2>/dev/null
```

Naming differs by host — `/usr/local/php85/bin/php`, `/usr/local/bin/ea-php83`
and `/opt/alt/php83/usr/bin/php` are all the same idea. Note down whichever one
you have; step 7 needs it.

> **The CLI PHP and the website's PHP are two different settings.** The version
> you see here is the shell's. The one serving the site is set in cPanel →
> *Select PHP Version* (on CloudLinux hosts, the PHP Selector). Set them to the
> same version, or you will be debugging a site that behaves unlike anything you
> can reproduce in the terminal. That screen is also where the extensions from
> step 1 are ticked.

**Composer is frequently not installed**, and not on `PATH` even when it is.
Check first:

```bash
ls -la /opt/cpanel/composer/bin/composer /usr/local/bin/composer 2>/dev/null; command -v composer composer2
```

If there is none, install your own — this is Composer's official sequence, and
the hash check is the part not to skip:

```bash
mkdir -p ~/bin && cd ~ \
  && php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
  && php -r "if (hash_file('sha384','composer-setup.php') === trim(file_get_contents('https://composer.github.io/installer.sig'))) { echo 'verified'.PHP_EOL; } else { unlink('composer-setup.php'); echo 'CORRUPT'.PHP_EOL; exit(1); }" \
  && php composer-setup.php --install-dir=$HOME/bin --filename=composer \
  && rm composer-setup.php \
  && echo 'export PATH="$HOME/bin:$PATH"' >> ~/.bashrc && export PATH="$HOME/bin:$PATH"
```

Install dependencies, then generate your own application key:

```bash
cd ~/agentpro-new && composer install --no-dev --optimize-autoloader
```

```bash
cd ~/agentpro-new && cp .env.example .env && php artisan key:generate
```

`key:generate` needs the framework, so Composer has to run first. Generating a
key here is simpler and more reliable than copying the one Softaculous made —
you are going to rewrite the rest of that file in step 4 regardless.

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

Put it in its final home:

```bash
mv ~/agentpro-new ~/agentpro
```

Leave the Softaculous install where it is for now. Once step 5 has the document
root pointing at `~/agentpro/public` and the site loads, you can clear it out.
Deleting it first leaves you with nothing serving while you debug.

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

Leave `APP_KEY` exactly as `key:generate` set it. Changing it later logs
everyone out and makes existing encrypted columns unreadable.

---

## 5. Point the domain at `public/`

This is the step people skip, and skipping it publishes `.env` to the internet.
Laravel is built to serve `public/` and nothing above it. Everything above it is
your source, your credentials and your customers' uploads.

Which method you get depends on the panel, so check which one you have first:
cPanel is on port 2083 and DirectAdmin on 2222.

### cPanel

**Domains** → your domain → *Manage* → set **Document Root** to
`/home/<user>/agentpro/public`. Then remove the Softaculous redirect stub:
`rm -f ~/agentpro/index.php`.

### DirectAdmin

A user-level account has no document-root field — the docroot is fixed at
`~/domains/<domain>/public_html`. (The `|?DOCROOT=|` directive in *Custom HTTPD
Configurations* does the job, but is usually reseller-only. Look under **Menu**
for it first; if it is there, that is the cleanest answer.)

Otherwise, replace `public_html` with a symlink to the app's public folder:

```bash
cd ~/domains/<domain> \
  && mv public_html public_html.old \
  && ln -s /home/<user>/agentpro/public public_html
```

Rename rather than delete, so one command puts it back. This is the approach to
prefer on DirectAdmin: `public_path()` keeps resolving correctly, the `storage`
symlink from step 6 stays valid, and deploys need no extra step.

### If neither works

Some Apache configurations refuse to follow a symlinked docroot — a 403 on every
page. Then copy the public folder into place and repoint its bootstrap:

```bash
rm -rf ~/domains/<domain>/public_html/* \
  && rm -f ~/domains/<domain>/public_html/.env \
           ~/domains/<domain>/public_html/.env.example \
           ~/domains/<domain>/public_html/.editorconfig \
           ~/domains/<domain>/public_html/.gitattributes \
           ~/domains/<domain>/public_html/.gitignore \
  && cp -r ~/agentpro/public/. ~/domains/<domain>/public_html/
```

> **`rm -rf dir/*` does not delete dotfiles**, and the Softaculous install left
> a `.env` among them. Clear the visible files and you are looking at a tidy
> directory with a live set of database credentials still in it, inside the
> document root, served over HTTP — Laravel's `public/.htaccess` has no rule
> against `.env`, and not every host blocks dotfiles itself. Always finish with
> `ls -la` and read the list, not `ls`.

Then edit `public_html/index.php`. **Three** paths point one level up, not two —
the maintenance-mode check is easy to miss, and missing it means `artisan down`
silently does nothing. Replace the lot with one base:

```php
$base = '/home/<user>/agentpro';

if (file_exists($maintenance = $base.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $base.'/vendor/autoload.php';

$app = require_once $base.'/bootstrap/app.php';
```

Then recreate the media symlink by hand, because `storage:link` pointed it at
the app's own `public/`, which is no longer what gets served:

```bash
rm -f ~/domains/<domain>/public_html/storage \
  && ln -s /home/<user>/agentpro/storage/app/public ~/domains/<domain>/public_html/storage
```

The cost of this one is that **every** deploy touching `public/` — CSS, the
banner photographs, `sw.js` — needs the `cp -r` repeated. It is the last resort,
not the default.

> **Never solve this with an `.htaccess` in `public_html` that rewrites into the
> app folder.** It appears to work, and it leaves `.env`, `storage/` and your
> whole source tree inside the document root, one misconfiguration away from
> being downloadable.

### Confirm it, in this order

```
https://<domain>/up       → 200
https://<domain>/          → the Agentpro home page
https://<domain>/.env      → 404 or 403. If this downloads, STOP: delete the file,
                             then rotate the database password it just published
https://<domain>/public/   → 404
```

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

First get your two real paths. Cron has almost no `PATH`, so both have to be
absolute — a bare `php` in a cron line is the classic reason a scheduler that
works in the terminal does nothing on a timer:

```bash
which php && echo ~
```

That prints something like `/usr/local/php85/bin/php` and `/home/agentpr3`.
Substitute both into the lines below — **including the angle brackets**, which
are not part of the command. Bash reads a stray `<` as input redirection, so
pasting the template unedited fails with a confusing "No such file or directory".

These go in cPanel → **Cron Jobs**, not the terminal. Add both, *Once Per Minute*
(`* * * * *`):

**The scheduler**

```
cd <home>/agentpro && <php> artisan schedule:run >> <home>/cron.log 2>&1
```

**The queue worker**

```
<php> <home>/agentpro/artisan queue:work --stop-when-empty --max-time=55 --tries=3 >> <home>/cron.log 2>&1
```

Both log to a file rather than `/dev/null` on purpose. A cron that discards its
own errors is the most common reason for "nothing is ever sent" on shared
hosting, and the message you threw away is the one that would have told you why.
Once you have watched it run clean for a few days, switch both to `/dev/null` —
otherwise the file grows without limit.

Check the wiring before trusting it:

```bash
cd ~/agentpro && php artisan schedule:list   # the five commands and their next run
tail -20 ~/cron.log                          # a minute after saving. Empty is good
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

Two halves. From your own machine:

```bash
git add -A && git commit -m "what changed" && git push
```

Then on the server, one command:

```bash
~/agentpro/deploy.sh
```

`deploy.sh` lives in the repository. It takes the site down, pulls, installs,
migrates, syncs the public folder if this install needs it, rebuilds all three
caches and restarts the queue — and brings the site back up even if a step in
the middle fails. Make it executable once, on the first deploy:

```bash
chmod +x ~/agentpro/deploy.sh
```

**On the copy layout, tell it where the webroot is**, or it will pull new code
and leave the browser looking at the old CSS:

```bash
echo 'export WEBROOT=~/domains/<domain>/public_html' >> ~/.bashrc && source ~/.bashrc
```

Leave `WEBROOT` unset on a symlinked document root — there is nothing to copy,
because the webroot *is* `public/`. That is the single biggest argument for the
symlink: with it, a deploy has no step anybody can forget.

If your CLI PHP is not on `PATH` as `php`, pass it:
`PHP=/usr/local/php85/bin/php ~/agentpro/deploy.sh`

> **`index.php` is never copied to the webroot**, deliberately. On the copy
> layout it has been edited to point at the application directory, while the one
> in the repository points one level up from `public/` — which from the webroot
> is nothing at all. Overwriting it is a white screen on every page, and it is
> the single easiest way to break this layout.

Back up the database before any deployment that carries a migration. In cPanel
that is *Backup* → *Download a MySQL Database Backup*; in DirectAdmin,
*Databases* → the database → *Download*. It takes about ten seconds and is the
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
| `composer: command not found` | Not installed or not on `PATH` — step 3 |
| A command fails with `No such file or directory` naming a word from this guide | A `<placeholder>` was pasted unedited; bash read `<` as a redirect |
| Scheduler works when you run it by hand, never on the timer | The cron line uses a bare `php`. Cron has no useful `PATH` — use the absolute path |
| Works in the terminal, 500s in the browser | CLI PHP and the website's PHP are different versions — step 3 |
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
