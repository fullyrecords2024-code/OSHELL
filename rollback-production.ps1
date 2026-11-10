[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [string]$VerifiedHostKeyFingerprint,
    [Parameter(Mandatory)]
    [ValidatePattern('^/home/oshelski/[A-Za-z0-9._/-]+$')]
    [string]$ApplicationRoot,
    [Parameter(Mandatory)]
    [ValidatePattern('^https://[^/\s?#]+/?$')]
    [string]$ProductionUrl,
    [Parameter(Mandatory)]
    [ValidatePattern('^(\/api\/v1|https://[^/\s?#]+\/api\/v1)\/?$')]
    [string]$ApiBaseUrl,
    [Parameter(Mandatory)]
    [ValidateSet('ROLLBACK APP ONLY')]
    [string]$Confirm,
    [string]$PuTTYDirectory = (Join-Path $env:LOCALAPPDATA 'Microsoft\WinGet\Packages\PuTTY.PuTTY_Microsoft.Winget.Source_8wekyb3d8bbwe')
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

if ($ApplicationRoot -match '(^|/)\.\.?(/|$)') {
    throw 'ApplicationRoot must not contain path traversal.'
}
$plink = Join-Path $PuTTYDirectory 'plink.exe'
if (-not (Test-Path -LiteralPath $plink -PathType Leaf)) {
    throw "PuTTY Plink was not found: $plink"
}
& (Join-Path $PSScriptRoot 'test-ssh.ps1') `
    -VerifiedHostKeyFingerprint $VerifiedHostKeyFingerprint `
    -PuTTYDirectory $PuTTYDirectory
if ($LASTEXITCODE -and $LASTEXITCODE -ne 0) {
    throw 'SSH preflight failed.'
}
$typed = Read-Host 'Type ROLLBACK APP ONLY to switch application code. Database and uploads will not be changed'
if ($typed -cne 'ROLLBACK APP ONLY') {
    throw 'Application-only rollback was not confirmed.'
}

$apiUri = if ($ApiBaseUrl.StartsWith('/')) {
    [Uri]::new([Uri]$ProductionUrl, $ApiBaseUrl.TrimEnd('/') + '/')
} else {
    [Uri]$ApiBaseUrl
}
$healthUri = [Uri]::new($apiUri, '/up')
$siteUri = [Uri]::new([Uri]$ProductionUrl, '/')
$bashQuoteReplacement = "'" + '"' + "'" + '"' + "'"
$remoteLiteral = "'" + $ApplicationRoot.Replace("'", $bashQuoteReplacement) + "'"
$siteLiteral = "'" + $siteUri.AbsoluteUri.Replace("'", $bashQuoteReplacement) + "'"
$healthLiteral = "'" + $healthUri.AbsoluteUri.Replace("'", $bashQuoteReplacement) + "'"
$remoteScript = @'
set -Eeuo pipefail
test "$(id -un)" = 'oshelski'
test "$HOME" = '/home/oshelski'
app_root=__APP_ROOT__
site_url=__SITE_URL__
health_url=__HEALTH_URL__
command -v curl >/dev/null
command -v realpath >/dev/null
test -d "$app_root" && test -O "$app_root" && test ! -L "$app_root" || { echo 'Application root is missing, unowned, or a symlink.' >&2; exit 60; }
app_root_real="$(realpath -m "$app_root")"
case "$app_root_real" in "$HOME"/*) ;; *) echo 'Application root resolves outside the account home.' >&2; exit 61;; esac
mv --help 2>&1 | grep -q -- '-T' || { echo 'GNU mv with -T support is required for atomic rollback.' >&2; exit 57; }
current="$app_root/current"
previous="$app_root/previous"
test -L "$current" || { echo 'Current release is not a symlink.' >&2; exit 62; }
test -L "$previous" || { echo 'No previous release is available.' >&2; exit 63; }
old_current="$(realpath -m "$current")"
target="$(realpath -m "$previous")"
case "$old_current" in "$app_root_real"/releases/*) ;; *) echo 'Current release points outside the releases directory.' >&2; exit 64;; esac
case "$target" in "$app_root_real"/releases/*) ;; *) echo 'Previous release points outside the releases directory.' >&2; exit 65;; esac
test -d "$current" -a -d "$previous" || { echo 'Current or previous release symlink is broken.' >&2; exit 66; }
test -d "$target/backend" -a -d "$target/frontend/dist" || { echo 'Previous release is incomplete.' >&2; exit 67; }
test "$old_current" != "$target" || { echo 'Current and previous releases are identical.' >&2; exit 68; }
printf 'CURRENT_RELEASE=%s\nROLLBACK_TARGET=%s\n' "$old_current" "$target"
ln -s "$target" "$app_root/.current-rollback"
ln -s "$old_current" "$app_root/.previous-rollback"
mv -Tf "$app_root/.previous-rollback" "$previous"
mv -Tf "$app_root/.current-rollback" "$current"
if ! curl --fail --silent --show-error --max-time 30 "$site_url" -o /dev/null ||
   ! curl --fail --silent --show-error --max-time 30 "$health_url" -o /dev/null; then
    ln -s "$old_current" "$app_root/.current-rollback-failed"
    ln -s "$target" "$app_root/.previous-rollback-failed"
    mv -Tf "$app_root/.previous-rollback-failed" "$previous"
    mv -Tf "$app_root/.current-rollback-failed" "$current"
    echo 'Rollback health check failed; the prior active release was restored.' >&2
    exit 56
fi
printf 'ROLLED_BACK_TO=%s\n' "$target"
'@
$remoteScript = $remoteScript.Replace('__APP_ROOT__', $remoteLiteral)
$remoteScript = $remoteScript.Replace('__SITE_URL__', $siteLiteral)
$remoteScript = $remoteScript.Replace('__HEALTH_URL__', $healthLiteral)
$temporaryScript = Join-Path ([IO.Path]::GetTempPath()) ([Guid]::NewGuid().ToString('N') + '.sh')
try {
    $encoding = [Text.UTF8Encoding]::new($false)
    [IO.File]::WriteAllText($temporaryScript, $remoteScript, $encoding)
    $arguments = @(
        '-batch', '-ssh', '-P', '6622', '-hostkey', $VerifiedHostKeyFingerprint,
        '-agent', '-l', 'oshelski', '169.58.230.169', '-m', $temporaryScript
    )
    & $plink @arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Pinned PuTTY application-only rollback failed with exit code $LASTEXITCODE."
    }
} finally {
    Remove-Item -LiteralPath $temporaryScript -Force -ErrorAction SilentlyContinue
}
