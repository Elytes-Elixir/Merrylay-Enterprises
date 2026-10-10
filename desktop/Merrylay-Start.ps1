param(
    [string]$XamppRoot,
    [string]$ApplicationUrl
)

$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($XamppRoot)) {
    $XamppRoot = $env:MERRYLAY_XAMPP_ROOT
}
if ([string]::IsNullOrWhiteSpace($XamppRoot)) {
    $XamppRoot = 'C:\xampp'
}
$XamppRoot = [System.IO.Path]::GetFullPath($XamppRoot)

if ([string]::IsNullOrWhiteSpace($ApplicationUrl)) {
    $ApplicationUrl = $env:MERRYLAY_APP_URL
}

$projectRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$htdocsRoot = [System.IO.Path]::GetFullPath((Join-Path $XamppRoot 'htdocs'))

if ([string]::IsNullOrWhiteSpace($ApplicationUrl)) {
    $htdocsPrefix = $htdocsRoot.TrimEnd('\') + '\'
    if ([System.String]::Equals($projectRoot, $htdocsRoot, [System.StringComparison]::OrdinalIgnoreCase)) {
        $relativeProjectPath = ''
    }
    elseif ($projectRoot.StartsWith($htdocsPrefix, [System.StringComparison]::OrdinalIgnoreCase)) {
        $relativeProjectPath = $projectRoot.Substring($htdocsPrefix.Length).Replace('\', '/')
    }
    else {
        throw "The project is not beneath '$htdocsRoot'. Set MERRYLAY_APP_URL to the application's login URL."
    }

    $ApplicationUrl = 'http://localhost/'
    if (-not [string]::IsNullOrWhiteSpace($relativeProjectPath)) {
        $ApplicationUrl += $relativeProjectPath.TrimEnd('/') + '/'
    }
    $ApplicationUrl += 'auth/login.php'
}

$parsedUrl = $null
if (-not [System.Uri]::TryCreate($ApplicationUrl, [System.UriKind]::Absolute, [ref]$parsedUrl) -or
    $parsedUrl.Scheme -notin @('http', 'https')) {
    throw "MERRYLAY_APP_URL must be an absolute HTTP or HTTPS URL. Received: '$ApplicationUrl'."
}

$apacheExecutable = Join-Path $XamppRoot 'apache\bin\httpd.exe'
$apacheStartScript = Join-Path $XamppRoot 'apache_start.bat'
$mysqlExecutable = Join-Path $XamppRoot 'mysql\bin\mysqld.exe'
$mysqlStartScript = Join-Path $XamppRoot 'mysql_start.bat'

foreach ($requiredPath in @($apacheExecutable, $apacheStartScript, $mysqlExecutable, $mysqlStartScript)) {
    if (-not (Test-Path -LiteralPath $requiredPath -PathType Leaf)) {
        throw "Required XAMPP file was not found: '$requiredPath'. Check MERRYLAY_XAMPP_ROOT."
    }
}

$stateDirectory = Join-Path $env:LOCALAPPDATA 'Merrylay\DesktopLauncher'
if (-not (Test-Path -LiteralPath $stateDirectory -PathType Container)) {
    New-Item -ItemType Directory -Path $stateDirectory -Force | Out-Null
}
$rootHash = [System.Security.Cryptography.SHA256]::Create()
try {
    $rootHashBytes = $rootHash.ComputeHash([System.Text.Encoding]::UTF8.GetBytes($XamppRoot.ToLowerInvariant()))
}
finally {
    $rootHash.Dispose()
}
$rootHashText = ([System.BitConverter]::ToString($rootHashBytes)).Replace('-', '').Substring(0, 16)
$statePath = Join-Path $stateDirectory "launcher-$rootHashText.json"

$ownedProcesses = @{
    XamppRoot = $XamppRoot
    ApachePid = $null
    MySqlPid = $null
}
if (Test-Path -LiteralPath $statePath -PathType Leaf) {
    $previousState = Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json
    if ($previousState.XamppRoot -eq $XamppRoot) {
        foreach ($service in @(
            @{ Name = 'ApachePid'; ProcessName = 'httpd'; Executable = $apacheExecutable },
            @{ Name = 'MySqlPid'; ProcessName = 'mysqld'; Executable = $mysqlExecutable }
        )) {
            $oldPid = $previousState.($service.Name)
            if ($null -ne $oldPid -and (Get-Process -Id ([int]$oldPid) -ErrorAction SilentlyContinue)) {
                $process = Get-CimInstance Win32_Process -Filter "ProcessId = $([int]$oldPid)" -ErrorAction Stop
                if ($process.ExecutablePath -and
                    [System.String]::Equals(
                        [System.IO.Path]::GetFullPath($process.ExecutablePath),
                        [System.IO.Path]::GetFullPath($service.Executable),
                        [System.StringComparison]::OrdinalIgnoreCase
                    )) {
                    $ownedProcesses[$service.Name] = [int]$oldPid
                }
            }
        }
    }
}

function Get-XamppProcesses {
    param(
        [string]$ProcessName,
        [string]$Executable
    )

    $candidates = @(Get-CimInstance Win32_Process -Filter "Name = '$ProcessName.exe'" -ErrorAction Stop)
    $matches = @()
    foreach ($candidate in $candidates) {
        if ([string]::IsNullOrWhiteSpace($candidate.ExecutablePath)) {
            throw "Cannot verify the executable path for running process $($candidate.ProcessId) ($ProcessName.exe). Run this launcher with sufficient permission."
        }
        if ([System.String]::Equals(
                [System.IO.Path]::GetFullPath($candidate.ExecutablePath),
                [System.IO.Path]::GetFullPath($Executable),
                [System.StringComparison]::OrdinalIgnoreCase
            )) {
            $matches += $candidate
        }
    }
    return $matches
}

function Save-LauncherState {
    $ownedProcesses | ConvertTo-Json | Set-Content -LiteralPath $statePath -Encoding UTF8
}

function Start-XamppService {
    param(
        [string]$ServiceName,
        [string]$ProcessName,
        [string]$Executable,
        [string]$StartScript,
        [string]$StateProperty
    )

    $running = @(Get-XamppProcesses -ProcessName $ProcessName -Executable $Executable)
    if ($running.Count -gt 0) {
        Write-Host "$ServiceName is already running; leaving it unchanged."
        return
    }
    $existingProcessIds = @($running | ForEach-Object { [int]$_.ProcessId })

    Write-Host "Starting $ServiceName..."
    $command = '/d /c call "{0}"' -f $StartScript
    Start-Process -FilePath $env:ComSpec -ArgumentList $command -WorkingDirectory $XamppRoot -WindowStyle Hidden | Out-Null

    $deadline = (Get-Date).AddSeconds(30)
    $startedByLauncher = @()
    do {
        Start-Sleep -Milliseconds 500
        $running = @(Get-XamppProcesses -ProcessName $ProcessName -Executable $Executable)
        $startedByLauncher = @($running | Where-Object { $existingProcessIds -notcontains [int]$_.ProcessId })
    } while ($running.Count -eq 0 -and (Get-Date) -lt $deadline)

    if ($running.Count -eq 0) {
        throw "$ServiceName did not start. Check the XAMPP control panel and configuration."
    }
    if ($startedByLauncher.Count -eq 0) {
        Write-Host "$ServiceName was started by another process during launch; it will not be stopped by this launcher."
        return
    }

    $ownedProcesses[$StateProperty] = [int]$startedByLauncher[0].ProcessId
    Save-LauncherState
}

Start-XamppService -ServiceName 'Apache' -ProcessName 'httpd' `
    -Executable $apacheExecutable -StartScript $apacheStartScript -StateProperty 'ApachePid'
Start-XamppService -ServiceName 'MySQL' -ProcessName 'mysqld' `
    -Executable $mysqlExecutable -StartScript $mysqlStartScript -StateProperty 'MySqlPid'

Save-LauncherState
Start-Process -FilePath $ApplicationUrl
Write-Host "Opened Merrylay: $ApplicationUrl"
