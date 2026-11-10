[CmdletBinding()]
param(
    [string]$KeyPath,
    [ValidatePattern('^SHA256:[A-Za-z0-9+/]+={0,2}$')]
    [string]$VerifiedHostKeyFingerprint,
    [string]$RemoteCommand,
    [string]$RemoteScriptPath,
    [switch]$OpenGui,
    [switch]$RestartPageant,
    [string]$PuTTYDirectory = (Join-Path $env:LOCALAPPDATA 'Microsoft\WinGet\Packages\PuTTY.PuTTY_Microsoft.Winget.Source_8wekyb3d8bbwe')
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

if (-not $KeyPath) {
    $scriptRoot = if ($PSScriptRoot) { $PSScriptRoot } else { Split-Path -Parent $MyInvocation.MyCommand.Path }
    $workspaceKey = Join-Path $scriptRoot 'backend\id_rsa.1793363358.ppk'
    $protectedKey = Join-Path $env:USERPROFILE '.ssh\oshel-production\production-key.ppk'
    $KeyPath = if (Test-Path -LiteralPath $workspaceKey -PathType Leaf) { $workspaceKey } else { $protectedKey }
}

$hostName = '169.58.230.169'
$sshPort = 6622
$sshUser = 'oshelski'
$expectedHome = '/home/oshelski'

function Set-PrivateAcl {
    param([Parameter(Mandatory)][string]$Path, [switch]$Directory)

    $currentSid = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
    $inheritance = if ($Directory) { '(OI)(CI)' } else { '' }
    & icacls.exe $Path /inheritance:r /grant:r `
        "*${currentSid}:${inheritance}F" '*S-1-5-18:(F)' '*S-1-5-32-544:(F)' | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "Could not restrict permissions on $Path."
    }
}

if (-not (Test-Path -LiteralPath $KeyPath -PathType Leaf)) {
    throw "Private key not found: $KeyPath"
}
$sourceKey = (Resolve-Path -LiteralPath $KeyPath).Path
if ([IO.Path]::GetExtension($sourceKey) -ne '.ppk') {
    throw 'KeyPath must point to a PuTTY PPK file.'
}

$privateDirectory = Join-Path $env:USERPROFILE '.ssh\oshel-production'
$privateKey = Join-Path $privateDirectory 'production-key.ppk'
$pageant = Join-Path $PuTTYDirectory 'pageant.exe'
$plink = Join-Path $PuTTYDirectory 'plink.exe'
$putty = Join-Path $PuTTYDirectory 'putty.exe'
$requiredTools = if ($OpenGui) { @($putty) } else { @($pageant, $plink) }
foreach ($tool in $requiredTools) {
    if (-not (Test-Path -LiteralPath $tool -PathType Leaf)) {
        throw "Required PuTTY executable not found: $tool"
    }
}

$null = New-Item -ItemType Directory -Path $privateDirectory -Force
Set-PrivateAcl -Path $privateDirectory -Directory
if (-not $sourceKey.Equals($privateKey, [StringComparison]::OrdinalIgnoreCase)) {
    Copy-Item -LiteralPath $sourceKey -Destination $privateKey -Force
    Write-Host "Copied the PPK to the protected location: $privateKey"
    Write-Host 'Delete the workspace copy once you no longer need it; private keys should not live in a project folder.'
}
Set-PrivateAcl -Path $privateKey

if ($OpenGui) {
    Write-Host "Opening PuTTY to ${sshUser}@${hostName}:${sshPort} with the protected PPK."
    Write-Host 'Enter the passphrase locally if PuTTY prompts, and accept the host key only if it matches your provider''s record.'
    Start-Process -FilePath $putty -ArgumentList @('-P', "$sshPort", '-l', $sshUser, '-i', ('"' + $privateKey + '"'), $hostName)
    return
}
if (-not $VerifiedHostKeyFingerprint) {
    throw 'Supply -VerifiedHostKeyFingerprint for scripted SSH, or use -OpenGui.'
}

$pageantProcess = Get-Process -Name PAGEANT -ErrorAction SilentlyContinue
if ($RestartPageant -and $pageantProcess) {
    Write-Host 'Restarting Pageant to load this key only.'
    Stop-Process -Name PAGEANT -Force
    $pageantProcess = $null
}
if (-not $pageantProcess) {
    Write-Host 'Starting Pageant with the protected PPK. Enter its passphrase only in Pageant''s local prompt.'
    Start-Process -FilePath $pageant -ArgumentList @('"' + $privateKey + '"')
    Start-Sleep -Seconds 2
    if (-not (Get-Process -Name PAGEANT -ErrorAction SilentlyContinue)) {
        throw 'Pageant did not start.'
    }
} else {
    Write-Host 'Pageant is already running; the script will use the keys it currently holds.'
}

if ($RemoteScriptPath) {
    if (-not (Test-Path -LiteralPath $RemoteScriptPath -PathType Leaf)) {
        throw "Remote script not found: $RemoteScriptPath"
    }
    $remoteScript = [IO.File]::ReadAllText((Resolve-Path -LiteralPath $RemoteScriptPath).Path)
} elseif ($RemoteCommand) {
    $remoteScript = $RemoteCommand
} else {
    $remoteScript = @'
set -eu
actual_user="$(id -un)"
actual_home="$HOME"
printf 'REMOTE_USER=%s\n' "$actual_user"
printf 'REMOTE_HOME=%s\n' "$actual_home"
printf 'REMOTE_HOST=%s\n' "$(hostname 2>/dev/null || echo unknown)"
printf 'SYSTEM='; uname -srm
if [ "$actual_user" != '__EXPECTED_USER__' ] || [ "$actual_home" != '__EXPECTED_HOME__' ]; then
    echo 'Remote username or home directory does not match the confirmed account.' >&2
    exit 20
fi
'@
    $remoteScript = $remoteScript.Replace('__EXPECTED_USER__', $sshUser)
    $remoteScript = $remoteScript.Replace('__EXPECTED_HOME__', $expectedHome)
}

$temporaryScript = Join-Path ([IO.Path]::GetTempPath()) ([Guid]::NewGuid().ToString('N') + '.sh')
try {
    $encoding = [Text.UTF8Encoding]::new($false)
    [IO.File]::WriteAllText($temporaryScript, $remoteScript, $encoding)
    $arguments = @(
        '-batch', '-ssh', '-P', $sshPort, '-hostkey', $VerifiedHostKeyFingerprint,
        '-agent', '-l', $sshUser, $hostName, '-m', $temporaryScript
    )
    & $plink @arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Pinned PuTTY SSH command failed with exit code $LASTEXITCODE. If authentication failed, confirm the key is loaded in Pageant (or rerun with -RestartPageant)."
    }
} finally {
    Remove-Item -LiteralPath $temporaryScript -Force -ErrorAction SilentlyContinue
}
