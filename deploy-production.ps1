[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [string]$VerifiedHostKeyFingerprint,
    [Parameter(Mandatory)]
    [ValidatePattern('^/home/oshelski/[A-Za-z0-9._/-]+$')]
    [string]$ApplicationRoot,
    [Parameter(Mandatory)]
    [ValidatePattern('^/home/oshelski/[A-Za-z0-9._/-]+$')]
    [string]$LaravelDocumentRoot,
    [Parameter(Mandatory)]
    [ValidatePattern('^/home/oshelski/[A-Za-z0-9._/-]+$')]
    [string]$FrontendDocumentRoot,
    [Parameter(Mandatory)]
    [ValidatePattern('^/home/oshelski/[A-Za-z0-9._/-]+$')]
    [string]$SharedEnvironmentPath,
    [Parameter(Mandatory)]
    [ValidatePattern('^/home/oshelski/[A-Za-z0-9._/-]+$')]
    [string]$SharedStoragePath,
    [Parameter(Mandatory)]
    [ValidatePattern('^/home/oshelski/[A-Za-z0-9._/-]+$')]
    [string]$ExistingLaravelPath,
    [Parameter(Mandatory)]
    [ValidatePattern('^https://[^/\s?#]+/?$')]
    [string]$ProductionUrl,
    [Parameter(Mandatory)]
    [ValidatePattern('^(\/api\/v1|https://[^/\s?#]+\/api\/v1)\/?$')]
    [string]$ApiBaseUrl,
    [Parameter(Mandatory)]
    [ValidateSet('DEPLOY PRODUCTION')]
    [string]$Confirm,
    [string]$PuTTYDirectory = (Join-Path $env:LOCALAPPDATA 'Microsoft\WinGet\Packages\PuTTY.PuTTY_Microsoft.Winget.Source_8wekyb3d8bbwe')
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Assert-SafeRemotePath {
    param([string]$Path, [string]$Name)
    if ($Path -notmatch '^/home/oshelski/[A-Za-z0-9._/-]+$' -or $Path -match '(^|/)\.\.?(/|$)') {
        throw "$Name must be an absolute, traversal-free path beneath /home/oshelski."
    }
}

function ConvertTo-BashLiteral {
    param([string]$Value)
    $bashQuoteReplacement = "'" + '"' + "'" + '"' + "'"
    return "'" + $Value.Replace("'", $bashQuoteReplacement) + "'"
}

function Invoke-RemoteBash {
    param([string]$Script)
    $temporaryScript = Join-Path ([IO.Path]::GetTempPath()) ([Guid]::NewGuid().ToString('N') + '.sh')
    try {
        $encoding = [Text.UTF8Encoding]::new($false)
        [IO.File]::WriteAllText($temporaryScript, $Script, $encoding)
        $arguments = @(
            '-batch', '-ssh', '-P', '6622', '-hostkey', $VerifiedHostKeyFingerprint,
            '-agent', '-l', 'oshelski', '169.58.230.169', '-m', $temporaryScript
        )
        & $plink @arguments
        if ($LASTEXITCODE -ne 0) {
            throw "Pinned PuTTY remote command failed with exit code $LASTEXITCODE."
        }
    } finally {
        Remove-Item -LiteralPath $temporaryScript -Force -ErrorAction SilentlyContinue
    }
}

foreach ($entry in @(
    @{ Path = $ApplicationRoot; Name = 'ApplicationRoot' },
    @{ Path = $LaravelDocumentRoot; Name = 'LaravelDocumentRoot' },
    @{ Path = $FrontendDocumentRoot; Name = 'FrontendDocumentRoot' },
    @{ Path = $SharedEnvironmentPath; Name = 'SharedEnvironmentPath' },
    @{ Path = $SharedStoragePath; Name = 'SharedStoragePath' },
    @{ Path = $ExistingLaravelPath; Name = 'ExistingLaravelPath' }
)) {
    Assert-SafeRemotePath -Path $entry.Path -Name $entry.Name
}

$apiUri = if ($ApiBaseUrl.StartsWith('/')) {
    [Uri]::new([Uri]$ProductionUrl, $ApiBaseUrl.TrimEnd('/') + '/')
} else {
    [Uri]$ApiBaseUrl
}
$healthUri = [Uri]::new($apiUri, '/up')
$siteUri = [Uri]$ProductionUrl
$siteUri = [Uri]::new($siteUri, '/')

$root = (Resolve-Path -LiteralPath $PSScriptRoot).Path
$plink = Join-Path $PuTTYDirectory 'plink.exe'
$pscp = Join-Path $PuTTYDirectory 'pscp.exe'
foreach ($tool in @($plink, $pscp)) {
    if (-not (Test-Path -LiteralPath $tool -PathType Leaf)) {
        throw "Required PuTTY executable not found: $tool"
    }
}
$backend = Join-Path $root 'backend'
$frontend = Join-Path $root 'frontend'
$php = Get-Command php.exe -ErrorAction SilentlyContinue
$composer = Get-Command composer.bat -ErrorAction SilentlyContinue
$npm = Get-Command npm.cmd -ErrorAction SilentlyContinue
if (-not $php -or -not $composer -or -not $npm) {
    throw 'PHP, Composer, and npm must be available before a production deployment.'
}

Write-Host 'Verifying the pinned SSH host key and inspecting the remote hosting account.'
& (Join-Path $PSScriptRoot 'test-ssh.ps1') `
    -VerifiedHostKeyFingerprint $VerifiedHostKeyFingerprint `
    -ApplicationPath $ExistingLaravelPath `
    -ExpectedEnvironmentPath $SharedEnvironmentPath `
    -PuTTYDirectory $PuTTYDirectory
if ($LASTEXITCODE -and $LASTEXITCODE -ne 0) {
    throw 'SSH preflight failed.'
}
$inspectionReviewed = Read-Host 'Review the remote .env names, migration status, database identity/grants and filesystem permissions. Type REMOTE PREFLIGHT REVIEWED to continue'
if ($inspectionReviewed -cne 'REMOTE PREFLIGHT REVIEWED') {
    throw 'Remote hosting and database inspection was not acknowledged; deployment stopped.'
}

Write-Host 'Running backend tests and dependency audit.'
Push-Location $backend
try {
    & $php.Source artisan test
    if ($LASTEXITCODE -ne 0) { throw 'Backend tests failed.' }
    & $composer.Source audit --locked
    if ($LASTEXITCODE -ne 0) { throw 'Composer dependency audit failed.' }
} finally {
    Pop-Location
}

$oldApiBase = $env:VITE_API_BASE_URL
$oldPublicUrl = $env:VITE_PUBLIC_SITE_URL
$env:VITE_API_BASE_URL = $ApiBaseUrl
$env:VITE_PUBLIC_SITE_URL = $ProductionUrl.TrimEnd('/')
Push-Location $frontend
try {
    Write-Host 'Building the React/TanStack production bundle.'
    & $npm.Source run build
    if ($LASTEXITCODE -ne 0) { throw 'Frontend production build failed.' }
} finally {
    Pop-Location
    if ($null -eq $oldApiBase) { Remove-Item Env:VITE_API_BASE_URL -ErrorAction SilentlyContinue } else { $env:VITE_API_BASE_URL = $oldApiBase }
    if ($null -eq $oldPublicUrl) { Remove-Item Env:VITE_PUBLIC_SITE_URL -ErrorAction SilentlyContinue } else { $env:VITE_PUBLIC_SITE_URL = $oldPublicUrl }
}

$releaseId = [DateTime]::UtcNow.ToString('yyyyMMddTHHmmssZ') + '-' + [Guid]::NewGuid().ToString('N').Substring(0, 8)
$temporaryRoot = Join-Path ([IO.Path]::GetTempPath()) "oshel-production-$releaseId"
$stage = Join-Path $temporaryRoot 'stage'
$archive = Join-Path $temporaryRoot "$releaseId.tar.gz"
$remoteIncomingDirectory = '/home/oshelski/.oshel-deploy/incoming'
$remoteArchive = "$remoteIncomingDirectory/$releaseId.tar.gz"
try {
    $null = New-Item -ItemType Directory -Path (Join-Path $stage 'backend') -Force
    $null = New-Item -ItemType Directory -Path (Join-Path $stage 'frontend') -Force
    foreach ($directory in @('app', 'bootstrap', 'config', 'public', 'resources', 'routes')) {
        $source = Join-Path $backend $directory
        $destination = Join-Path (Join-Path $stage 'backend') $directory
        $null = New-Item -ItemType Directory -Path $destination -Force
        $excludedDirectories = @((Join-Path $source 'storage'))
        if ($directory -eq 'bootstrap') {
            $excludedDirectories += Join-Path $source 'cache'
        }
        if ($directory -eq 'public') {
            $excludedDirectories += Join-Path $source 'storage'
        }
        & robocopy.exe $source $destination /E /XJ /SL /R:2 /W:1 /XD $excludedDirectories | Out-Null
        if ($LASTEXITCODE -ge 8) { throw "Could not stage backend directory $directory." }
    }
    $bootstrapCache = Join-Path (Join-Path $stage 'backend\bootstrap') 'cache'
    $null = New-Item -ItemType Directory -Path $bootstrapCache -Force
    $bootstrapCacheGitignore = Join-Path (Join-Path $backend 'bootstrap\cache') '.gitignore'
    if (Test-Path -LiteralPath $bootstrapCacheGitignore -PathType Leaf) {
        Copy-Item -LiteralPath $bootstrapCacheGitignore -Destination $bootstrapCache -Force
    }
    foreach ($file in @('artisan', 'composer.json', 'composer.lock', 'openapi.yaml', '.env.example')) {
        Copy-Item -LiteralPath (Join-Path $backend $file) -Destination (Join-Path $stage 'backend') -Force
    }
    foreach ($directory in @('migrations', 'seeders', 'data')) {
        $source = Join-Path (Join-Path $backend 'database') $directory
        $destination = Join-Path (Join-Path $stage 'backend\database') $directory
        $null = New-Item -ItemType Directory -Path $destination -Force
        & robocopy.exe $source $destination /E /XJ /R:2 /W:1 | Out-Null
        if ($LASTEXITCODE -ge 8) { throw "Could not stage database $directory." }
    }
    Copy-Item -LiteralPath (Join-Path $frontend 'dist') -Destination (Join-Path $stage 'frontend') -Recurse -Force

    & tar.exe -czf $archive -C $stage backend frontend
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $archive)) {
        throw 'Could not create the deployment archive.'
    }
    $archiveContents = & tar.exe -tzf $archive
    if ($LASTEXITCODE -ne 0) { throw 'Could not inspect the deployment archive.' }
    if ($archiveContents | Where-Object {
        $_ -notmatch '^(backend|frontend)/' -or
        $_ -match '(^/|(^|/)\.\.?(/|$)|(^|/)[A-Za-z]:)'
    }) {
        throw 'The archive contains a path outside the backend/frontend roots or a traversal path.'
    }
    if ($archiveContents | Where-Object {
        $_ -match '(^|/)\.env($|/)|(^|/)storage/|(^|/)vendor/|(^|/)node_modules/|\.sqlite($|/)' -or
        ($_ -match '(^|/)bootstrap/cache/[^/]+$' -and $_ -notmatch '(^|/)bootstrap/cache/\.gitignore$')
    }) {
        throw 'The archive contains a forbidden environment, storage, vendor, node_modules, generated cache, or SQLite path.'
    }
    $archiveDetails = & tar.exe -tvzf $archive
    if ($LASTEXITCODE -ne 0) { throw 'Could not inspect deployment archive file types.' }
    if ($archiveDetails | Where-Object { $_ -match '^[lh]' }) {
        throw 'The archive contains a symbolic or hard link; deployment archives must contain regular files only.'
    }

    $preflight = @'
set -eu
home_expected='/home/oshelski'
app_root=__APP_ROOT__
laravel_docroot=__LARAVEL_DOCROOT__
frontend_docroot=__FRONTEND_DOCROOT__
env_path=__ENV_PATH__
storage_path=__STORAGE_PATH__
incoming=__INCOMING__
release_id=__RELEASE_ID__
test "$(id -un)" = 'oshelski'
test "$HOME" = "$home_expected"
case "$app_root:$laravel_docroot:$frontend_docroot:$env_path:$storage_path" in *'..'*) echo 'Path traversal rejected.' >&2; exit 30;; esac
case "$app_root:$laravel_docroot:$frontend_docroot:$env_path:$storage_path" in *' '*) echo 'Whitespace in remote paths is not supported.' >&2; exit 31;; esac
php -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' || { echo 'PHP 8.3+ required.' >&2; exit 32; }
php -m | grep -qi '^pdo_mysql$' || php -m | grep -qi '^pdo_pgsql$' || { echo 'A supported PDO database driver is required.' >&2; exit 33; }
command -v composer >/dev/null
command -v tar >/dev/null
command -v realpath >/dev/null
command -v readlink >/dev/null
command -v curl >/dev/null
tar --version 2>/dev/null | grep -q 'GNU tar' || { echo 'GNU tar is required for safe release extraction.' >&2; exit 50; }
mv --help 2>&1 | grep -q -- '-T' || { echo 'GNU mv with -T support is required for atomic release switching.' >&2; exit 37; }
test -f "$env_path"
test -d "$storage_path"
test -r "$env_path" -a -w "$storage_path"
test -w "$HOME"
test ! -e "$app_root" || test -O "$app_root"
for path in "$app_root" "$env_path" "$storage_path"; do
    real_path="$(realpath -m "$path")"
    case "$real_path" in "$HOME"/*) ;; *) echo "Path resolves outside the account home: $path" >&2; exit 38;; esac
done
umask 077
test ! -L "$app_root" || { echo 'ApplicationRoot must not itself be a symlink.' >&2; exit 39; }
env_mode="$(stat -c '%a' "$env_path")"
env_other_bits=$(( (8#$env_mode) & 7 ))
test "$env_other_bits" -eq 0 || { echo 'The .env file is accessible to other users; secure it before deployment.' >&2; exit 40; }
mkdir -p "$app_root/releases" "$incoming"
test -w "$app_root/releases" -a -w "$incoming"
test -L "$laravel_docroot" && test "$(readlink "$laravel_docroot")" = "$app_root/current/backend/public" || { echo 'Laravel document root must already be a symlink to current/backend/public.' >&2; exit 34; }
test -L "$frontend_docroot" && test "$(readlink "$frontend_docroot")" = "$app_root/current/frontend/dist" || { echo 'Frontend document root must already be a symlink to current/frontend/dist.' >&2; exit 35; }
case "$env_path" in "$laravel_docroot"/*|"$frontend_docroot"/*) echo '.env must be outside web roots.' >&2; exit 36;; esac
case "$storage_path" in "$laravel_docroot"/*|"$frontend_docroot"/*) echo 'Storage must be outside web roots.' >&2; exit 41;; esac
env_real="$(realpath -m "$env_path")"
storage_real="$(realpath -m "$storage_path")"
laravel_docroot_real="$(realpath -m "$laravel_docroot")"
frontend_docroot_real="$(realpath -m "$frontend_docroot")"
case "$env_real" in "$laravel_docroot_real"/*|"$frontend_docroot_real"/*) echo '.env resolves inside a web root.' >&2; exit 44;; esac
case "$storage_real" in "$laravel_docroot_real"/*|"$frontend_docroot_real"/*) echo 'Storage resolves inside a web root.' >&2; exit 45;; esac
if [ -L "$app_root/current" ]; then
    current_real="$(realpath -m "$app_root/current")"
    case "$current_real" in "$app_root"/releases/*) ;; *) echo 'Current release points outside the approved releases directory.' >&2; exit 42;; esac
    test -d "$app_root/current" || { echo 'Current release symlink is broken.' >&2; exit 46; }
elif [ -e "$app_root/current" ]; then
    echo 'Current exists but is not a symlink.' >&2
    exit 43
fi
if [ -e "$app_root/previous" ] || [ -L "$app_root/previous" ]; then
    test -L "$app_root/previous" || { echo 'Previous exists but is not a symlink.' >&2; exit 47; }
    previous_real="$(realpath -m "$app_root/previous")"
    case "$previous_real" in "$app_root"/releases/*) ;; *) echo 'Previous release points outside the approved releases directory.' >&2; exit 48;; esac
    test -d "$app_root/previous" || { echo 'Previous release symlink is broken.' >&2; exit 49; }
fi
test ! -e "$app_root/releases/$release_id"
printf 'REMOTE_PREFLIGHT_OK\n'
'@
    $preflight = $preflight.Replace('__APP_ROOT__', (ConvertTo-BashLiteral $ApplicationRoot))
    $preflight = $preflight.Replace('__LARAVEL_DOCROOT__', (ConvertTo-BashLiteral $LaravelDocumentRoot))
    $preflight = $preflight.Replace('__FRONTEND_DOCROOT__', (ConvertTo-BashLiteral $FrontendDocumentRoot))
    $preflight = $preflight.Replace('__ENV_PATH__', (ConvertTo-BashLiteral $SharedEnvironmentPath))
    $preflight = $preflight.Replace('__STORAGE_PATH__', (ConvertTo-BashLiteral $SharedStoragePath))
    $preflight = $preflight.Replace('__INCOMING__', (ConvertTo-BashLiteral $remoteIncomingDirectory))
    $preflight = $preflight.Replace('__RELEASE_ID__', (ConvertTo-BashLiteral $releaseId))
    Invoke-RemoteBash $preflight

    Write-Host "Prepared release $releaseId. This deployment will not run database migrations."
    Write-Host 'Pending migrations cause a hard stop; database backup and migration approval must be handled under the DBA runbook.'
    $typed = Read-Host "Type DEPLOY PRODUCTION to deploy $releaseId"
    if ($typed -cne 'DEPLOY PRODUCTION') {
        throw 'Production deployment was not confirmed.'
    }

    $copyArguments = @(
        '-batch', '-hostkey', $VerifiedHostKeyFingerprint, '-agent', '-P', '6622',
        $archive, "oshelski@169.58.230.169:$remoteArchive"
    )
    & $pscp @copyArguments
    if ($LASTEXITCODE -ne 0) { throw 'Could not upload the release archive through pinned PSCP.' }

    $deploy = @'
set -Eeuo pipefail
umask 077
app_root=__APP_ROOT__
laravel_docroot=__LARAVEL_DOCROOT__
frontend_docroot=__FRONTEND_DOCROOT__
env_path=__ENV_PATH__
storage_path=__STORAGE_PATH__
release_id=__RELEASE_ID__
archive=__ARCHIVE__
site_url=__SITE_URL__
health_url=__HEALTH_URL__
release="$app_root/releases/$release_id"
current="$app_root/current"
previous="$app_root/previous"
switched=0
old_target=''
cleanup() {
    status=$?
    if [ "$switched" -eq 1 ] && [ "$status" -ne 0 ] && [ -n "$old_target" ]; then
        ln -s "$old_target" "$app_root/.current-restore-$release_id"
        mv -Tf "$app_root/.current-restore-$release_id" "$current"
        echo 'Health check failed; restored the previous application symlink. Database was not changed.' >&2
    fi
    rm -f -- "$archive"
    exit "$status"
}
trap cleanup EXIT
test -f "$archive"
mkdir -m 700 "$release"
tar --no-same-owner --no-same-permissions -xzf "$archive" -C "$release"
test -f "$release/backend/artisan"
ln -s "$env_path" "$release/backend/.env"
ln -s "$storage_path" "$release/backend/storage"
mkdir -p "$storage_path/app/public" "$release/backend/bootstrap/cache"
ln -s "$storage_path/app/public" "$release/backend/public/storage"
cd "$release/backend"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
php -r '$app=require "bootstrap/app.php"; $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap(); if (config("app.env") !== "production" || config("app.debug") !== false) {fwrite(STDERR,"Production APP_ENV/APP_DEBUG check failed.\n"); exit(40);} echo "APP_ENV=production APP_DEBUG=false\n";'
status="$(php artisan migrate:status --no-interaction)"
printf '%s\n' "$status"
if printf '%s\n' "$status" | grep -q 'Pending'; then
    echo 'Pending migrations detected. Deployment aborted before switching releases. No migration or database write was run.' >&2
    exit 41
fi
php artisan config:cache
php artisan route:list --except-vendor >/dev/null
old_target="$(readlink "$current" 2>/dev/null || true)"
if [ -e "$current" ] && [ ! -L "$current" ]; then
    echo 'Current is not a symlink; refusing to replace it.' >&2
    exit 42
fi
if [ -n "$old_target" ]; then
    ln -s "$old_target" "$app_root/.previous-$release_id"
    mv -Tf "$app_root/.previous-$release_id" "$previous"
fi
ln -s "$release" "$app_root/.current-$release_id"
mv -Tf "$app_root/.current-$release_id" "$current"
switched=1
curl --fail --silent --show-error --max-time 30 "$site_url" -o /dev/null
curl --fail --silent --show-error --max-time 30 "$health_url" -o /dev/null
printf 'DEPLOYED_RELEASE=%s\n' "$release"
printf 'PREVIOUS_RELEASE=%s\n' "$old_target"
switched=0
'@
    $deploy = $deploy.Replace('__APP_ROOT__', (ConvertTo-BashLiteral $ApplicationRoot))
    $deploy = $deploy.Replace('__LARAVEL_DOCROOT__', (ConvertTo-BashLiteral $LaravelDocumentRoot))
    $deploy = $deploy.Replace('__FRONTEND_DOCROOT__', (ConvertTo-BashLiteral $FrontendDocumentRoot))
    $deploy = $deploy.Replace('__ENV_PATH__', (ConvertTo-BashLiteral $SharedEnvironmentPath))
    $deploy = $deploy.Replace('__STORAGE_PATH__', (ConvertTo-BashLiteral $SharedStoragePath))
    $deploy = $deploy.Replace('__RELEASE_ID__', (ConvertTo-BashLiteral $releaseId))
    $deploy = $deploy.Replace('__ARCHIVE__', (ConvertTo-BashLiteral $remoteArchive))
    $deploy = $deploy.Replace('__SITE_URL__', (ConvertTo-BashLiteral $siteUri.AbsoluteUri))
    $deploy = $deploy.Replace('__HEALTH_URL__', (ConvertTo-BashLiteral $healthUri.AbsoluteUri))
    Invoke-RemoteBash $deploy

    Write-Host "Release $releaseId deployed and remote HTTP health checks passed."
} finally {
    if (Test-Path -LiteralPath $temporaryRoot) {
        Remove-Item -LiteralPath $temporaryRoot -Recurse -Force
    }
}
