<#
Install Meilisearch and set up Exment for it, on Windows Server (IIS and others).
Run PowerShell as Administrator, in the folder of Exment:

    cd C:\inetpub\exment
    powershell -ExecutionPolicy Bypass -File vendor\exceedone\exment\resources\meilisearch\install-meilisearch.ps1

It does, and can be run again (what is there is kept):
  1. Meilisearch: C:\meilisearch\meilisearch.exe, a new master key in C:\meilisearch\master-key.txt
     (Administrators and SYSTEM only), and the service "Meilisearch" (127.0.0.1:7700 only), with NSSM
  2. Exment: php artisan exment:meili-setup (.env, tables, system settings, index)
  3. the queue worker as the service "ExmentMeiliWorker"
  4. the scheduler as the task "ExmentScheduler" (php artisan schedule:run every minute)

NSSM: give -Nssm, or put nssm.exe in PATH or C:\nssm\, or have winget (it is installed with "winget install nssm").
#>
param(
    [string]$ExmentDir = (Get-Location).Path,
    [string]$Php = '',
    [string]$Version = 'v1.10.3',
    [string]$InstallDir = 'C:\meilisearch',
    [string]$Nssm = '',
    [string]$Index = '',
    [switch]$NoScheduler,
    [switch]$NoDefenderExclusion
)
$ErrorActionPreference = 'Stop'

function Say($text) { Write-Host ''; Write-Host "== $text" -ForegroundColor Cyan }
function Fail($text) { Write-Host "ERROR: $text" -ForegroundColor Red; exit 1 }

$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { Fail 'run PowerShell as Administrator (it installs services).' }
$ExmentDir = (Resolve-Path $ExmentDir).Path
if (-not (Test-Path (Join-Path $ExmentDir 'artisan'))) { Fail "no artisan in $ExmentDir : run it in the folder of Exment, or give -ExmentDir." }

if (-not $Php) { $cmd = Get-Command php -ErrorAction SilentlyContinue; if ($cmd) { $Php = $cmd.Source } }
if (-not $Php -or -not (Test-Path $Php)) { Fail 'no php: give -Php C:\php\php.exe (the one IIS uses).' }

if (-not $Nssm) {
    $cmd = Get-Command nssm -ErrorAction SilentlyContinue
    if ($cmd) { $Nssm = $cmd.Source } elseif (Test-Path 'C:\nssm\nssm.exe') { $Nssm = 'C:\nssm\nssm.exe' }
}
if (-not $Nssm) {
    $winget = Get-Command winget -ErrorAction SilentlyContinue
    if (-not $winget) { Fail 'no NSSM: download it from https://nssm.cc/download, put nssm.exe in C:\nssm\, and run this again (or give -Nssm).' }
    Write-Host 'installing NSSM with winget...'
    & winget install --id NSSM.NSSM -e --accept-source-agreements --accept-package-agreements --silent | Out-Null
    $env:Path = [Environment]::GetEnvironmentVariable('Path', 'Machine') + ';' + [Environment]::GetEnvironmentVariable('Path', 'User')
    $cmd = Get-Command nssm -ErrorAction SilentlyContinue
    if (-not $cmd) { Fail 'NSSM was not found after "winget install": open a new PowerShell and run this again, or give -Nssm.' }
    $Nssm = $cmd.Source
}

function Invoke-Nssm { & $Nssm @args | Out-Null; if ($LASTEXITCODE -ne 0) { Fail "nssm $($args -join ' ') failed." } }
function Install-Service($name, $exe, $parameters, $dir) {
    if (Get-Service $name -ErrorAction SilentlyContinue) { & $Nssm stop $name confirm | Out-Null } else { Invoke-Nssm install $name $exe }
    Invoke-Nssm set $name Application $exe
    Invoke-Nssm set $name AppParameters $parameters
    Invoke-Nssm set $name AppDirectory $dir
    Invoke-Nssm set $name AppExit Default Restart
    Invoke-Nssm set $name Start SERVICE_AUTO_START
}

Write-Host "Exment: $ExmentDir ($Php)"

# ---------------------------------------------------------------- 1. Meilisearch
Say "1/4 Meilisearch $Version"
New-Item -ItemType Directory -Force $InstallDir | Out-Null
$exe = Join-Path $InstallDir 'meilisearch.exe'
$have = ''
if (Test-Path $exe) { try { $have = [string](& $exe --version) } catch { $have = '' } }
if ($have -match [regex]::Escape($Version.TrimStart('v'))) {
    Write-Host 'already installed'
} else {
    if (Get-Service Meilisearch -ErrorAction SilentlyContinue) { & $Nssm stop Meilisearch confirm | Out-Null }
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    $tmp = Join-Path $InstallDir 'meilisearch.download'
    Invoke-WebRequest -UseBasicParsing -Uri "https://github.com/meilisearch/meilisearch/releases/download/$Version/meilisearch-windows-amd64.exe" -OutFile $tmp
    Move-Item -Force $tmp $exe
    & $exe --version
}

$keyFile = Join-Path $InstallDir 'master-key.txt'
if (Test-Path $keyFile) {
    $key = (Get-Content $keyFile -Raw).Trim()
    Write-Host "keeping the master key in $keyFile"
} else {
    $bytes = New-Object byte[] 16
    [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    $key = -join ($bytes | ForEach-Object { $_.ToString('x2') })
    Set-Content -Path $keyFile -Value $key -Encoding ASCII -NoNewline
    Write-Host "made a new master key in $keyFile"
}
# the key and the index: Administrators and SYSTEM only
& icacls $keyFile /inheritance:r /grant:r '*S-1-5-32-544:F' '*S-1-5-18:F' | Out-Null
$data = Join-Path $InstallDir 'data.ms'

# the key goes in the environment of the service, not on its command line
Install-Service 'Meilisearch' $exe "--db-path `"$data`" --http-addr 127.0.0.1:7700 --env production --no-analytics" $InstallDir
Invoke-Nssm set Meilisearch AppEnvironmentExtra "MEILI_MASTER_KEY=$key"
Invoke-Nssm start Meilisearch

if (-not $NoDefenderExclusion) {
    try { Add-MpPreference -ExclusionPath $data -ErrorAction Stop } catch { Write-Host "(could not exclude $data from Windows Defender: $($_.Exception.Message))" }
}

$host_ = 'http://127.0.0.1:7700'
$up = $false
for ($i = 0; $i -lt 30 -and -not $up; $i++) {
    try { $up = ((Invoke-RestMethod "$host_/health" -TimeoutSec 2).status -eq 'available') } catch { Start-Sleep -Seconds 1 }
}
if (-not $up) { Fail "Meilisearch did not start: see the service Meilisearch, and $InstallDir" }
Write-Host "running on $host_"

# ---------------------------------------------------------------- 2. Exment
Say '2/4 Exment (php artisan exment:meili-setup)'
Push-Location $ExmentDir
try {
    if (-not (Test-Path 'vendor\meilisearch\meilisearch-php')) {
        if (-not (Get-Command composer -ErrorAction SilentlyContinue)) { Fail 'meilisearch/meilisearch-php is not installed and there is no composer: run "composer require meilisearch/meilisearch-php" here.' }
        & composer require meilisearch/meilisearch-php --no-interaction
        if ($LASTEXITCODE -ne 0) { Fail 'composer require failed.' }
    }
    $env:MEILI_MASTER_KEY = $key
    $setupArgs = @('artisan', 'exment:meili-setup', "--host=$host_", '--no-interaction')
    if ($Index) { $setupArgs += "--index=$Index" }
    & $Php @setupArgs
    $code = $LASTEXITCODE
    Remove-Item Env:\MEILI_MASTER_KEY
    if ($code -ne 0) { Fail 'exment:meili-setup failed (see above).' }
} finally { Pop-Location }

# ---------------------------------------------------------------- 3. queue worker
Say '3/4 Queue worker (ExmentMeiliWorker)'
function Get-EnvValue($name) {
    $line = Get-Content (Join-Path $ExmentDir '.env') | Where-Object { $_ -match "^$name=" } | Select-Object -Last 1
    if ($line) { return ($line -replace "^$name=", '').Trim('"', "'", ' ') }
    return ''
}
$sync = Get-EnvValue 'MEILISEARCH_SYNC_QUEUE'; if (-not $sync) { $sync = 'default' }
$reindex = Get-EnvValue 'MEILISEARCH_REINDEX_QUEUE'; if (-not $reindex) { $reindex = $sync }
$queues = if ($reindex -ne $sync) { "$sync,$reindex" } else { $sync }
Install-Service 'ExmentMeiliWorker' $Php "artisan queue:work --queue=$queues --max-time=3600 --tries=3" $ExmentDir
Invoke-Nssm start ExmentMeiliWorker
Start-Sleep -Seconds 2
if ((Get-Service ExmentMeiliWorker).Status -ne 'Running') { Fail 'the worker did not start: see the service ExmentMeiliWorker.' }
Write-Host "running (queues: $queues)"

# ---------------------------------------------------------------- 4. scheduler
Say '4/4 Scheduler (ExmentScheduler)'
if ($NoScheduler) {
    Write-Host "skipped (-NoScheduler): run `"$Php $ExmentDir\artisan schedule:run`" every minute yourself"
} elseif (Get-ScheduledTask -TaskName ExmentScheduler -ErrorAction SilentlyContinue) {
    Write-Host 'already registered'
} else {
    & schtasks /create /tn ExmentScheduler /sc minute /mo 1 /ru SYSTEM /f /tr "`"$Php`" `"$ExmentDir\artisan`" schedule:run" | Out-Null
    if ($LASTEXITCODE -ne 0) { Fail 'schtasks /create failed.' }
    Write-Host 'registered'
}

Say 'Done'
Write-Host "Search with Meilisearch is on. Check it any time with: $Php artisan exment:meili-health --failed"
Write-Host "The master key is in $keyFile (Administrators only). Do not open port 7700 to the Internet."
