#!/usr/bin/env bash
#
# Deploy the current branch to a shared-hosting install.
#
# Run it on the server, from the application directory:
#
#     ~/agentpro/deploy.sh
#
# WHY A SCRIPT AND NOT A LIST OF COMMANDS IN A README. The order matters and two
# of the steps are easy to forget in a hurry — the caches, which otherwise serve
# yesterday's routes, and the public-folder sync, which is invisible when you
# forget it: the site keeps working and quietly serves the old CSS.
#
# WEBROOT is the one host-specific thing, so it is read from the environment.
# Leave it unset on a symlinked document root — there is nothing to copy,
# because the webroot *is* public/. Set it only on the copy layout:
#
#     WEBROOT=~/domains/example.com/public_html ~/agentpro/deploy.sh
#
# Put that line in ~/.bashrc and you can forget it exists.

set -euo pipefail

cd "$(dirname "$0")"

PHP="${PHP:-php}"

echo "==> Maintenance mode"
# Every request gets a 503 from here until the trap lifts it, including if this
# script dies half way. A deploy that fails in the middle and leaves the site
# down is annoying; one that fails and leaves it up, serving new code against an
# old schema, is a data problem.
$PHP artisan down || true
trap '$PHP artisan up' EXIT

echo "==> Code"
git pull --ff-only

echo "==> Dependencies"
composer install --no-dev --optimize-autoloader

echo "==> Database"
# --force because there is no terminal to answer the production prompt. Note
# what is NOT here: --seed. See the guard at the top of DatabaseSeeder.
$PHP artisan migrate --force

# Reference data — areas and amenities, without which the listing form has
# nothing to offer. Named explicitly, never a bare `db:seed`, which would run
# the development seeder and its six accounts with the password "password".
# Only touches an empty table, so running it every deploy is a no-op after the
# first and never undoes an administrator's edits.
$PHP artisan db:seed --class=ReferenceDataSeeder --force

if [ -n "${WEBROOT:-}" ]; then
    echo "==> Public assets -> $WEBROOT"
    # index.php is deliberately excluded. On this layout it has been edited to
    # point at the application directory, and the copy in the repository points
    # one level up from public/ — which, from the webroot, is nothing. Copying
    # it over is a white screen on every page, so it is never copied.
    find public -mindepth 1 -maxdepth 1 ! -name index.php \
        -exec cp -r {} "$WEBROOT/" \;

    # Dotfiles are not matched by the glob above and .htaccess is the one that
    # matters: without it every URL but the home page is a 404.
    cp public/.htaccess "$WEBROOT/.htaccess"
fi

echo "==> Caches"
# Rebuilt, not just cleared. A cached config ignores later .env edits, which is
# the bug where the file is obviously right and the app obviously disagrees.
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache

echo "==> Queue"
# Tells any worker still running to finish its job and exit, so the next one the
# cron starts is running the code that was just deployed.
$PHP artisan queue:restart

echo "==> Done"
