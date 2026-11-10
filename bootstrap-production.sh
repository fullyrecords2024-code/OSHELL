#!/usr/bin/env bash
# First-deploy bootstrap for the Oshel app on the main cPanel/LiteSpeed domain.
#
# Run as oshelski on the production host:
#   bash bootstrap-production.sh <release-id> <archive-path>
#
# It creates the release layout, installs Composer, provisions the MySQL
# database, writes the shared production .env, extracts the release, installs
# dependencies, runs migrations and seeders, creates the first Super Admin,
# switches the release symlink, and replaces WordPress in public_html with the
# SPA document root. WordPress is moved to a dated backup directory, never
# deleted. The switch is rolled back if the HTTP checks fail.
set -Eeuo pipefail
umask 077

RELEASE_ID="${1:?usage: bootstrap-production.sh <release-id> <archive-path>}"
ARCHIVE="${2:?usage: bootstrap-production.sh <release-id> <archive-path>}"

HOME_DIR="$HOME"
APP_ROOT="/home/oshelski/apps/oshel"
SHARED="/home/oshelski/apps/oshel/shared/oshel"
ENV_PATH="$SHARED/.env"
STORAGE_PATH="$SHARED/storage"
FRONTEND_DOCROOT="/home/oshelski/public_html"
CREDENTIALS="$HOME/.oshel-initial-credentials"
SITE_URL="https://oshelskincare.co.ke"
ADMIN_EMAIL="admin@oshelskincare.co.ke"
ADMIN_NAME="Oshel Administrator"
PHP83="/opt/cpanel/ea-php83/root/usr/bin/php"
COMPOSER="$HOME/bin/composer"
DB_NAME="oshelski_oshel"
DB_USER="oshelski_oshel"
MAIL_DOMAIN="oshelskincare.co.ke"
MAIL_LOCAL="no-reply"
MAIL_USER="$MAIL_LOCAL@$MAIL_DOMAIN"

log() { printf '[bootstrap] %s\n' "$*"; }
die() { printf '[bootstrap] ERROR: %s\n' "$*" >&2; exit 1; }

uapi_must() {
    local output
    if ! output="$(uapi --output=json "$@" 2>&1)"; then
        printf '%s\n' "$output" >&2
        return 1
    fi
    if ! printf '%s\n' "$output" | grep -q '"status":1'; then
        printf '%s\n' "$output" >&2
        return 1
    fi
}

[ "$(id -un)" = "oshelski" ] || die "unexpected SSH user"
[ "$HOME_DIR" = "/home/oshelski" ] || die "unexpected HOME"
case "$APP_ROOT:$SHARED:$FRONTEND_DOCROOT" in
    *..*) die "path traversal rejected" ;;
    *" "*) die "whitespace in remote paths is not supported" ;;
esac
[ -x "$PHP83" ] || die "PHP 8.3 CLI not found at $PHP83"
"$PHP83" -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' || die "PHP 8.3+ required"
[ -f "$ARCHIVE" ] || die "archive not found: $ARCHIVE"
command -v uapi >/dev/null || die "cPanel uapi not available"
command -v curl >/dev/null || die "curl not available"
command -v tar >/dev/null || die "tar not available"
command -v realpath >/dev/null || die "realpath not available"

log "creating directories"
mkdir -p "$APP_ROOT/releases" \
    "$STORAGE_PATH/app/public" \
    "$STORAGE_PATH/framework/cache/data" \
    "$STORAGE_PATH/framework/sessions" \
    "$STORAGE_PATH/framework/views" \
    "$STORAGE_PATH/logs" \
    "$HOME_DIR/bin" \
    "$(dirname "$ARCHIVE")"
chmod 700 "$APP_ROOT" "$SHARED" "$HOME_DIR/bin" || true
chmod -R u+rwX,g+rX,o-rwx "$STORAGE_PATH" || true

log "installing Composer"
if [ ! -s "$HOME_DIR/bin/composer.phar" ]; then
    installer_dir="$(mktemp -d)"
    trap 'rm -rf "$installer_dir"' EXIT
    curl -fsSL https://getcomposer.org/installer -o "$installer_dir/installer.php"
    expected_sig="$(curl -fsSL https://composer.github.io/installer.sig)"
    actual_sig="$("$PHP83" -r 'echo hash_file("sha384", $argv[1]);' "$installer_dir/installer.php")"
    [ "$expected_sig" = "$actual_sig" ] || die "Composer installer checksum mismatch"
    (cd "$installer_dir" && "$PHP83" -d allow_url_fopen=On installer.php --quiet)
    mv "$installer_dir/composer.phar" "$HOME_DIR/bin/composer.phar"
    rm -rf "$installer_dir"
    trap - EXIT
fi
chmod 700 "$HOME_DIR/bin/composer.phar"
printf '#!/usr/bin/env bash\nexec %s -d allow_url_fopen=On "$HOME/bin/composer.phar" "$@"\n' "$PHP83" > "$COMPOSER"
chmod 700 "$COMPOSER"
"$COMPOSER" --version >/dev/null || die "Composer failed to run"

log "provisioning MySQL database $DB_NAME"
databases="$(uapi --output=json Mysql list_databases 2>&1)" || { printf '%s\n' "$databases" >&2; die "could not list databases"; }
if ! printf '%s\n' "$databases" | grep -Fq "\"database\":\"$DB_NAME\""; then
    uapi_must Mysql create_database name="$DB_NAME" || die "could not create database"
fi

if [ -f "$ENV_PATH" ]; then
    DB_PASS="$(sed -n 's/^DB_PASSWORD=//p' "$ENV_PATH" | tail -n 1 | tr -d '"')"
    [ -n "$DB_PASS" ] || die "existing .env has no DB_PASSWORD"
    grep -Fq "DB_DATABASE=$DB_NAME" "$ENV_PATH" || die "existing .env targets a different database"
    MAIL_PASS="$(sed -n 's/^MAIL_PASSWORD=//p' "$ENV_PATH" | tail -n 1 | tr -d '"')"
else
    DB_PASS="Aa1!$("$PHP83" -r 'echo bin2hex(random_bytes(14));')"
    MAIL_PASS=""
fi
ADMIN_PASS="Aa1!$("$PHP83" -r 'echo bin2hex(random_bytes(10));')"

users="$(uapi --output=json Mysql list_users 2>&1)" || { printf '%s\n' "$users" >&2; die "could not list database users"; }
if printf '%s\n' "$users" | grep -Fq "\"user\":\"$DB_USER\""; then
    uapi_must Mysql set_password user="$DB_USER" password="$DB_PASS" || die "could not set database password"
else
    uapi_must Mysql create_user name="$DB_USER" password="$DB_PASS" || die "could not create database user"
fi
uapi_must Mysql set_privileges_on_database user="$DB_USER" database="$DB_NAME" privileges='ALL PRIVILEGES' || die "could not grant database privileges"
log "database ready"

mail_env_pass="$(sed -n 's/^MAIL_PASSWORD=//p' "$ENV_PATH" 2>/dev/null | tail -n 1 | tr -d '"')"
if [ -z "$MAIL_PASS" ]; then
    MAIL_PASS="Aa1!$("$PHP83" -r 'echo bin2hex(random_bytes(12));')"
fi
mailboxes="$(uapi --output=json Email list_pops 2>&1)" || { printf '%s\n' "$mailboxes" >&2; die "could not list mailboxes"; }
if printf '%s\n' "$mailboxes" | grep -Fq "\"email\":\"$MAIL_USER\"" || printf '%s\n' "$mailboxes" | grep -Fq "\"login\":\"$MAIL_USER\""; then
    if [ -z "$mail_env_pass" ]; then
        uapi_must Email passwd_pop email="$MAIL_LOCAL" domain="$MAIL_DOMAIN" password="$MAIL_PASS" || die "could not set the sending mailbox password"
    fi
else
    uapi_must Email add_pop email="$MAIL_LOCAL" domain="$MAIL_DOMAIN" password="$MAIL_PASS" quota=1024 || die "could not create the sending mailbox"
fi
log "sending mailbox $MAIL_USER ready"

if [ ! -f "$ENV_PATH" ]; then
    APP_KEY="$("$PHP83" -r 'echo "base64:".base64_encode(random_bytes(32));')"
    cat > "$ENV_PATH" <<'ENV'
APP_NAME="Oshel Skin Care & Beauty Clinic"
APP_ENV=production
APP_KEY=__APP_KEY__
APP_DEBUG=false
APP_URL=https://oshelskincare.co.ke
FRONTEND_URL=https://oshelskincare.co.ke
PUBLIC_SITE_URL=https://oshelskincare.co.ke

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US
APP_MAINTENANCE_DRIVER=file

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=__DB_NAME__
DB_USERNAME=__DB_USER__
DB_PASSWORD="__DB_PASS__"

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=true

SANCTUM_STATEFUL_DOMAINS=oshelskincare.co.ke,www.oshelskincare.co.ke

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
CACHE_STORE=database

MAIL_MAILER=smtp
MAIL_SCHEME=null
MAIL_HOST=__MAIL_DOMAIN__
MAIL_PORT=587
MAIL_USERNAME=__MAIL_USER__
MAIL_PASSWORD="__MAIL_PASS__"
MAIL_FROM_ADDRESS="__MAIL_USER__"
MAIL_FROM_NAME="${APP_NAME}"

PESAPAL_ENVIRONMENT=sandbox
MPESA_ENVIRONMENT=sandbox
ENV
    sed -i \
        -e "s|__APP_KEY__|$APP_KEY|" \
        -e "s|__DB_NAME__|$DB_NAME|" \
        -e "s|__DB_USER__|$DB_USER|" \
        -e "s|__DB_PASS__|$DB_PASS|" \
        -e "s|__MAIL_DOMAIN__|$MAIL_DOMAIN|" \
        -e "s|__MAIL_USER__|$MAIL_USER|" \
        -e "s|__MAIL_PASS__|$MAIL_PASS|" \
        "$ENV_PATH"
    chmod 600 "$ENV_PATH"
    log "created production .env"
else
    chmod 600 "$ENV_PATH"
    log "reusing existing production .env"
fi

RELEASE="$APP_ROOT/releases/$RELEASE_ID"
if [ -e "$RELEASE" ]; then
    log "resuming existing release $RELEASE_ID"
    [ -f "$RELEASE/backend/artisan" ] || die "existing release is incomplete: $RELEASE"
    [ -f "$RELEASE/frontend/dist/index.html" ] || die "existing release is incomplete: $RELEASE"
else
    log "extracting release $RELEASE_ID"
    mkdir -m 700 "$RELEASE"
    tar --no-same-owner --no-same-permissions -xzf "$ARCHIVE" -C "$RELEASE"
    [ -f "$RELEASE/backend/artisan" ] || die "archive is missing backend/artisan"
    [ -f "$RELEASE/backend/composer.lock" ] || die "archive is missing backend/composer.lock"
    [ -f "$RELEASE/frontend/dist/index.html" ] || die "archive is missing frontend/dist/index.html"
    [ -f "$RELEASE/frontend/dist/.htaccess" ] || die "archive is missing frontend/dist/.htaccess"

    ln -s "$ENV_PATH" "$RELEASE/backend/.env"
    ln -s "$STORAGE_PATH" "$RELEASE/backend/storage"
    mkdir -p "$RELEASE/backend/bootstrap/cache" "$STORAGE_PATH/app/public"
    ln -s "$STORAGE_PATH/app/public" "$RELEASE/backend/public/storage"
fi

# The document root serves the SPA and routes API/health/SEO paths to a root
# front controller, so the archive must ship the matching rewrite rules.
rm -f "$RELEASE/frontend/dist/api" "$RELEASE/frontend/dist/.well-known"
grep -Fq 'RewriteRule ^api(/.*)?$ index.php' "$RELEASE/frontend/dist/.htaccess" \
    || die "frontend/dist/.htaccess must rewrite API routes to the root front controller"
grep -Fq 'RewriteRule ^storage(/.*)?$ index.php' "$RELEASE/frontend/dist/.htaccess" \
    || die "frontend/dist/.htaccess must serve public storage through the front controller"

chmod -R u+rwX,g+rX "$RELEASE/backend" "$RELEASE/frontend" || true
chmod -R o+rX "$RELEASE/frontend/dist" "$RELEASE/backend/public" || true
chmod o+x "$HOME_DIR/apps" "$APP_ROOT" "$APP_ROOT/releases" "$RELEASE" "$RELEASE/frontend" 2>/dev/null || true
chmod 600 "$ENV_PATH"

cd "$RELEASE/backend"
if [ -f "$RELEASE/backend/vendor/autoload.php" ]; then
    log "Composer dependencies already installed"
else
    log "installing production Composer dependencies"
    "$COMPOSER" install --no-dev --no-interaction --prefer-dist --optimize-autoloader
fi
"$PHP83" -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap(); if (config("app.env") !== "production" || config("app.debug") !== false) {fwrite(STDERR,"Production APP_ENV/APP_DEBUG check failed.\n"); exit(40);} echo "APP_ENV=production APP_DEBUG=false\n";'
"$PHP83" artisan config:cache
"$PHP83" artisan route:list --except-vendor >/dev/null

log "running migrations"
"$PHP83" artisan migrate --force
log "seeding baseline data"
"$PHP83" artisan db:seed --force

log "creating initial Super Admin"
admin_output="$(
    OSHEL_BOOTSTRAP_ADMIN_EMAIL="$ADMIN_EMAIL" \
    OSHEL_BOOTSTRAP_ADMIN_NAME="$ADMIN_NAME" \
    OSHEL_BOOTSTRAP_ADMIN_PASSWORD="$ADMIN_PASS" \
    "$PHP83" -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$org = App\Models\Organization::query()->where("is_active", true)->first();
$branch = $org ? $org->branches()->where("is_active", true)->first() : null;
$user = App\Models\User::query()->firstOrNew(["email" => getenv("OSHEL_BOOTSTRAP_ADMIN_EMAIL")]);
$created = !$user->exists;
$user->name = getenv("OSHEL_BOOTSTRAP_ADMIN_NAME");
$user->organization_id = $org ? $org->id : null;
$user->branch_id = $branch ? $branch->id : null;
$user->is_active = true;
if ($created) {
    $user->password = Illuminate\Support\Facades\Hash::make(getenv("OSHEL_BOOTSTRAP_ADMIN_PASSWORD"));
    $user->password_reset_required = true;
}
$user->save();
$user->syncRoles(["Super Admin"]);
if ($user->email_verified_at === null) {
    $user->email_verified_at = now();
    $user->save();
}
if ($org && $branch) {
    $user->branchAssignments()->sync([
        $branch->id => [
            "organization_id" => $org->id,
            "effective_from" => now("UTC")->toDateString(),
            "effective_until" => null,
            "is_primary" => true,
        ],
    ]);
}
echo "ADMIN_READY ".$user->email." created=".($created ? "yes" : "no").PHP_EOL;
'
)"
printf '%s\n' "$admin_output"
printf '%s\n' "$admin_output" | grep -q 'ADMIN_READY' || die "initial admin creation failed"

if printf '%s\n' "$admin_output" | grep -q 'created=no'; then
    recorded_pass="$(sed -n 's/^ADMIN_PASSWORD=//p' "$CREDENTIALS" 2>/dev/null | tail -n 1)"
    [ -n "$recorded_pass" ] && ADMIN_PASS="$recorded_pass"
fi

{
    printf 'ADMIN_EMAIL=%s\n' "$ADMIN_EMAIL"
    printf 'ADMIN_PASSWORD=%s\n' "$ADMIN_PASS"
    printf 'DB_NAME=%s\n' "$DB_NAME"
    printf 'DB_USER=%s\n' "$DB_USER"
    printf 'DB_PASSWORD=%s\n' "$DB_PASS"
    printf 'MAIL_USERNAME=%s\n' "$MAIL_USER"
    printf 'MAIL_PASSWORD=%s\n' "$MAIL_PASS"
} > "$CREDENTIALS"
chmod 600 "$CREDENTIALS"

old_target=""
backup=""
switched=0

restore_on_failure() {
    local status=$?
    if [ "$status" -ne 0 ] && [ "$switched" -eq 1 ]; then
        printf '[bootstrap] health check failed; restoring the previous document root\n' >&2
        if [ -n "$backup" ] && [ -e "$backup" ]; then
            if [ -e "$FRONTEND_DOCROOT" ] || [ -L "$FRONTEND_DOCROOT" ]; then
                mv "$FRONTEND_DOCROOT" "$HOME_DIR/public_html.failed-$RELEASE_ID-$(date +%s)" || true
            fi
            mv "$backup" "$FRONTEND_DOCROOT" || true
        fi
        if [ -n "$old_target" ]; then
            ln -s "$old_target" "$APP_ROOT/.current-restore-$RELEASE_ID" || true
            mv -Tf "$APP_ROOT/.current-restore-$RELEASE_ID" "$APP_ROOT/current" || true
        else
            rm -f "$APP_ROOT/current" || true
        fi
    fi
    exit "$status"
}
trap restore_on_failure EXIT

old_target="$(readlink "$APP_ROOT/current" 2>/dev/null || true)"
if [ -e "$APP_ROOT/current" ] && [ ! -L "$APP_ROOT/current" ]; then
    die "current exists but is not a symlink"
fi
if [ -n "$old_target" ]; then
    ln -s "$old_target" "$APP_ROOT/.previous-$RELEASE_ID"
    mv -Tf "$APP_ROOT/.previous-$RELEASE_ID" "$APP_ROOT/previous"
fi
ln -s "$RELEASE" "$APP_ROOT/.current-$RELEASE_ID"
mv -Tf "$APP_ROOT/.current-$RELEASE_ID" "$APP_ROOT/current"
switched=1
log "release symlink switched"

publish_docroot() {
    cp -R -P "$RELEASE/frontend/dist/." "$FRONTEND_DOCROOT/" || die "could not publish the SPA document root"
    rm -f "$FRONTEND_DOCROOT/api" "$FRONTEND_DOCROOT/.well-known" "$FRONTEND_DOCROOT/storage"
    printf '%s\n' \
        '<?php' \
        "require '$APP_ROOT/current/backend/vendor/autoload.php';" \
        "(require '$APP_ROOT/current/backend/bootstrap/app.php')" \
        '    ->handleRequest(Illuminate\Http\Request::capture());' \
        > "$FRONTEND_DOCROOT/index.php"
    printf '%s\n' "$RELEASE_ID" > "$FRONTEND_DOCROOT/.oshel-release"
    chmod 755 "$FRONTEND_DOCROOT"
    find "$FRONTEND_DOCROOT" -type d -exec chmod 755 {} +
    find "$FRONTEND_DOCROOT" -type f -exec chmod 644 {} +
}

if [ -L "$FRONTEND_DOCROOT" ]; then
    [ "$(readlink "$FRONTEND_DOCROOT")" = "$APP_ROOT/current/frontend/dist" ] || die "unexpected frontend document-root symlink target"
    rm -f "$FRONTEND_DOCROOT"
    mkdir -p "$FRONTEND_DOCROOT"
    publish_docroot
elif [ -f "$FRONTEND_DOCROOT/.oshel-release" ]; then
    previous_docroot="$HOME_DIR/public_html.previous-$RELEASE_ID"
    [ ! -e "$previous_docroot" ] || die "previous document-root backup path already exists: $previous_docroot"
    log "replacing the current Oshel document root"
    mv "$FRONTEND_DOCROOT" "$previous_docroot" || die "could not move the existing document root"
    backup="$previous_docroot"
    mkdir -p "$FRONTEND_DOCROOT"
    publish_docroot
else
    wordpress_backup="$HOME_DIR/public_html.wordpress-backup-$(date +%Y%m%dT%H%M%S)"
    [ ! -e "$wordpress_backup" ] || die "WordPress backup path already exists: $wordpress_backup"
    mv "$FRONTEND_DOCROOT" "$wordpress_backup" || die "could not move the existing document root"
    backup="$wordpress_backup"
    mkdir -p "$FRONTEND_DOCROOT"
    publish_docroot
    log "WordPress moved to $backup"
fi

sleep 2
log "checking the SPA"
spa_ok=0
spa_code=""
for attempt in 1 2 3 4 5; do
    body="$(mktemp)"
    spa_code="$(curl -sS -o "$body" -w '%{http_code}' --max-time 20 "$SITE_URL/?deploy=$RELEASE_ID" || true)"
    if [ "$spa_code" = "200" ] && grep -Fq 'id="root"' "$body"; then
        spa_ok=1
        rm -f "$body"
        break
    fi
    rm -f "$body"
    sleep 3
done
[ "$spa_ok" -eq 1 ] || die "SPA health check failed (HTTP ${spa_code:-none})"

log "checking Laravel /up"
up_code=""
up_body="$(mktemp)"
for attempt in 1 2 3 4 5 6 7 8 9 10; do
    up_code="$(curl -sS -o "$up_body" -w '%{http_code}' --max-time 20 "$SITE_URL/up?deploy=$RELEASE_ID" || true)"
    [ "$up_code" = "200" ] && break
    sleep 3
done
if [ "$up_code" != "200" ]; then
    head -c 300 "$up_body" >&2 || true
    rm -f "$up_body"
    die "Laravel health check failed (HTTP $up_code)"
fi
rm -f "$up_body"

log "checking /api routing"
api_body="$(mktemp)"
api_code="$(curl -sS -H 'Accept: application/json' -o "$api_body" -w '%{http_code}' --max-time 20 "$SITE_URL/api/v1/users?deploy=$RELEASE_ID" || true)"
if [ "$api_code" != "401" ]; then
    head -c 500 "$api_body" >&2 || true
    rm -f "$api_body"
    die "API routing check failed (HTTP $api_code; expected 401)"
fi
rm -f "$api_body"

log "checking public storage routing"
storage_probe="oshel-health-$RELEASE_ID.txt"
printf 'ok\n' > "$STORAGE_PATH/app/public/$storage_probe" || die "could not write the storage probe"
chmod 644 "$STORAGE_PATH/app/public/$storage_probe" || true
storage_body="$(mktemp)"
storage_code="$(curl -sS -o "$storage_body" -w '%{http_code}' --max-time 20 "$SITE_URL/storage/$storage_probe?deploy=$RELEASE_ID" || true)"
if [ "$storage_code" != "200" ] || ! grep -q '^ok$' "$storage_body"; then
    rm -f "$storage_body" "$STORAGE_PATH/app/public/$storage_probe"
    die "public storage routing check failed (HTTP $storage_code)"
fi
rm -f "$storage_body" "$STORAGE_PATH/app/public/$storage_probe"

switched=0
trap - EXIT
rm -f "$ARCHIVE"

printf 'DEPLOYED_RELEASE=%s\n' "$RELEASE"
printf 'PREVIOUS_DOCROOT=%s\n' "${backup:-none}"
printf 'CREDENTIALS_FILE=%s\n' "$CREDENTIALS"
printf 'SITE_URL=%s\n' "$SITE_URL"
printf 'ADMIN_EMAIL=%s\n' "$ADMIN_EMAIL"
