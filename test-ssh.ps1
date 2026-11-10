[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [ValidatePattern('^SHA256:[A-Za-z0-9+/]+={0,2}$')]
    [string]$VerifiedHostKeyFingerprint,
    [string]$ApplicationPath,
    [string]$ExpectedEnvironmentPath,
    [string]$PuTTYDirectory = (Join-Path $env:LOCALAPPDATA 'Microsoft\WinGet\Packages\PuTTY.PuTTY_Microsoft.Winget.Source_8wekyb3d8bbwe')
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$hostName = '169.58.230.169'
$sshUser = 'oshelski'
$expectedHome = '/home/oshelski'
$plink = Join-Path $PuTTYDirectory 'plink.exe'
if (-not (Test-Path -LiteralPath $plink -PathType Leaf)) {
    throw "PuTTY Plink was not found: $plink"
}

foreach ($entry in @(
    @{ Path = $ApplicationPath; Name = 'ApplicationPath' },
    @{ Path = $ExpectedEnvironmentPath; Name = 'ExpectedEnvironmentPath' }
)) {
    if ($entry.Path -and (
        $entry.Path -notmatch '^/home/oshelski/[A-Za-z0-9._/-]+$' -or
        $entry.Path -match '(^|/)\.\.?(/|$)'
    )) {
        throw "$($entry.Name) must be a traversal-free path beneath $expectedHome."
    }
}
if ($ExpectedEnvironmentPath -and -not $ApplicationPath) {
    throw 'ExpectedEnvironmentPath requires ApplicationPath.'
}

$remoteScript = @'
set -eu
expected_user='oshelski'
expected_home='/home/oshelski'
actual_user="$(id -un)"
actual_home="$HOME"
printf 'REMOTE_USER=%s\nREMOTE_HOME=%s\n' "$actual_user" "$actual_home"
if [ "$actual_user" != "$expected_user" ] || [ "$actual_home" != "$expected_home" ]; then
    echo 'Remote username or home directory does not match the confirmed account.' >&2
    exit 20
fi
printf 'SYSTEM='; uname -srm
printf 'HOME_PERMISSIONS='; stat -c '%A %a %U:%G %n' "$HOME"
printf 'TOOLS\n'
for tool in php composer node npm tar gzip mysqldump mysql gpg curl realpath nginx apache2 httpd; do
    if command -v "$tool" >/dev/null 2>&1; then printf '%s=%s\n' "$tool" "$(command -v "$tool")"; else printf '%s=not-found\n' "$tool"; fi
done
if command -v php >/dev/null 2>&1; then
    php -v | head -n 1
    php -m | grep -Ei '^(curl|fileinfo|mbstring|openssl|pdo|pdo_mysql|pdo_pgsql|zip)$' || true
fi
printf 'HOME_CANDIDATES\n'
for path in "$HOME/public_html" "$HOME/www" "$HOME/htdocs" "$HOME/domains"; do
    if [ -e "$path" ] || [ -L "$path" ]; then
        stat -c '%A %a %U:%G %n -> %N' "$path"
        find "$path" -maxdepth 3 -type f \( -name artisan -o -name index.php -o -name .htaccess \) -print 2>/dev/null | head -n 80
    fi
done
printf 'LARAVEL_CANDIDATES\n'
find "$HOME" -maxdepth 6 -type f -name artisan -print 2>/dev/null | head -n 40
printf 'ENV_FILE_PATHS (values are never read by this inventory)\n'
find "$HOME" -maxdepth 6 -type f -name .env -print 2>/dev/null | head -n 40
app='__APP_PATH__'
expected_env='__EXPECTED_ENV_PATH__'
if [ -n "$app" ]; then
    case "$app" in "$HOME"/*) ;; *) echo 'Application path is outside the expected home.' >&2; exit 21;; esac
    if [ ! -f "$app/artisan" ] || [ ! -f "$app/.env" ]; then
        echo 'ApplicationPath must identify an existing Laravel root with artisan and .env.' >&2
        exit 22
    fi
    command -v realpath >/dev/null || { echo 'realpath is required for environment-path verification.' >&2; exit 24; }
    if [ -n "$expected_env" ] && [ "$(realpath -m "$app/.env")" != "$(realpath -m "$expected_env")" ]; then
        echo 'The inspected Laravel environment does not resolve to the approved shared .env path.' >&2
        exit 23
    fi
    printf 'APP_PERMISSIONS='; stat -c '%A %a %U:%G %n' "$app" "$app/.env" "$app/storage" "$app/public"
    printf 'DOTENV_KEY_NAMES (values redacted)\n'
    sed -n 's/^[[:space:]]*\([A-Za-z_][A-Za-z0-9_]*\)=.*/\1=<redacted>/p' "$app/.env" | sort -u
    printf 'DATABASE_STATUS (read-only)\n'
    (cd "$app" && php artisan migrate:status --no-interaction)
    printf 'DATABASE_PERMISSIONS (read-only; authentication details redacted)\n'
    (
        cd "$app"
        php <<'PHP'
<?php
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection = \Illuminate\Support\Facades\DB::connection();
echo 'DB_DRIVER='.$connection->getDriverName().PHP_EOL;
if ($connection->getDriverName() === 'mysql') {
    $server = $connection->selectOne('SELECT DATABASE() AS database_name, VERSION() AS server_version, CURRENT_USER() AS connected_user');
    echo 'DB_INFO='.json_encode($server, JSON_UNESCAPED_SLASHES).PHP_EOL;
    foreach ($connection->select('SHOW GRANTS') as $grant) {
        foreach ((array) $grant as $value) {
            $grantText = preg_replace('/\s+IDENTIFIED\b.*$/i', ' [authentication details redacted]', (string) $value);
            echo 'DB_GRANT='.$grantText.PHP_EOL;
        }
    }
} elseif ($connection->getDriverName() === 'pgsql') {
    $server = $connection->selectOne('SELECT current_database() AS database_name, version() AS server_version, current_user AS connected_user');
    echo 'DB_INFO='.json_encode($server, JSON_UNESCAPED_SLASHES).PHP_EOL;
    $databasePermissions = $connection->select("SELECT privilege, has_database_privilege(current_user, current_database(), privilege) AS allowed FROM unnest(ARRAY['CONNECT', 'CREATE', 'TEMPORARY']) AS privileges(privilege)");
    foreach ($databasePermissions as $permission) {
        echo 'DB_DATABASE_PERMISSION='.json_encode($permission, JSON_UNESCAPED_SLASHES).PHP_EOL;
    }
    $tablePermissions = $connection->select("SELECT table_schema, table_name, privilege_type FROM information_schema.role_table_grants WHERE grantee = current_user AND table_schema NOT IN ('pg_catalog', 'information_schema') ORDER BY table_schema, table_name, privilege_type LIMIT 250");
    foreach ($tablePermissions as $permission) {
        echo 'DB_TABLE_PERMISSION='.json_encode($permission, JSON_UNESCAPED_SLASHES).PHP_EOL;
    }
} else {
    echo 'Database permission details not queried for this driver.'.PHP_EOL;
}
PHP
    )
fi
'@
$remoteScript = $remoteScript.Replace('__APP_PATH__', [string]$ApplicationPath)
$remoteScript = $remoteScript.Replace('__EXPECTED_ENV_PATH__', [string]$ExpectedEnvironmentPath)

$temporaryScript = Join-Path ([IO.Path]::GetTempPath()) ([Guid]::NewGuid().ToString('N') + '.sh')
try {
    $encoding = [Text.UTF8Encoding]::new($false)
    [IO.File]::WriteAllText($temporaryScript, $remoteScript, $encoding)
    $arguments = @(
        '-batch', '-ssh', '-P', '6622', '-hostkey', $VerifiedHostKeyFingerprint,
        '-agent', '-l', $sshUser, $hostName, '-m', $temporaryScript
    )
    & $plink @arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Pinned PuTTY SSH preflight failed with exit code $LASTEXITCODE."
    }
} finally {
    Remove-Item -LiteralPath $temporaryScript -Force -ErrorAction SilentlyContinue
}
