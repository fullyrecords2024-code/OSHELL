[CmdletBinding()]
param(
    [string]$KeyPath,
    [string]$PuTTYDirectory = (Join-Path $env:LOCALAPPDATA 'Microsoft\WinGet\Packages\PuTTY.PuTTY_Microsoft.Winget.Source_8wekyb3d8bbwe')
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

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

$workspaceRoot = (Resolve-Path -LiteralPath $PSScriptRoot).Path.TrimEnd('\') + '\'
$privateDirectory = Join-Path $env:USERPROFILE '.ssh\oshel-production'
$privateKey = Join-Path $privateDirectory 'production-key.ppk'
if ($KeyPath) {
    $sourceKey = (Resolve-Path -LiteralPath $KeyPath).Path
} elseif (-not (Test-Path -LiteralPath $privateKey -PathType Leaf)) {
    throw 'Supply -KeyPath for the initial setup; later runs can reuse the protected PPK.'
}
if ($KeyPath -and $sourceKey.StartsWith($workspaceRoot, [StringComparison]::OrdinalIgnoreCase)) {
    throw 'The downloaded private key must remain outside the repository.'
}

$pageant = Join-Path $PuTTYDirectory 'pageant.exe'
$plink = Join-Path $PuTTYDirectory 'plink.exe'
$pscp = Join-Path $PuTTYDirectory 'pscp.exe'
foreach ($tool in @($pageant, $plink, $pscp)) {
    if (-not (Test-Path -LiteralPath $tool -PathType Leaf)) {
        throw "Required PuTTY executable not found: $tool"
    }
}

$null = New-Item -ItemType Directory -Path $privateDirectory -Force
Set-PrivateAcl -Path $privateDirectory -Directory
if (-not (Test-Path -LiteralPath $privateKey -PathType Leaf)) {
    Copy-Item -LiteralPath $sourceKey -Destination $privateKey
}
Set-PrivateAcl -Path $privateKey

Write-Host 'Starting Pageant with the protected PPK. Enter its passphrase only in Pageant’s local prompt.'
Start-Process -FilePath $pageant -ArgumentList @('"' + $privateKey + '"')
Start-Sleep -Seconds 2
if (-not (Get-Process -Name PAGEANT -ErrorAction SilentlyContinue)) {
    throw 'Pageant did not start.'
}

Write-Host 'Pageant is running. Complete its local passphrase prompt and verify the key appears in Pageant.'
Write-Host 'Plink and PSCP can reuse the unlocked key while Pageant remains running.'
Write-Host 'The host key is not trusted by this setup. Supply the provider-verified fingerprint to test-ssh.ps1.'
