param(
    [string]$XamppRoot
)

$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($XamppRoot)) {
    $XamppRoot = $env:MERRYLAY_XAMPP_ROOT
}
if ([string]::IsNullOrWhiteSpace($XamppRoot)) {
    $XamppRoot = 'C:\xampp'
}
$XamppRoot = [System.IO.Path]::GetFullPath($XamppRoot)

$stateDirectory = Join-Path $env:LOCALAPPDATA 'Merrylay\DesktopLauncher'
$rootHash = [System.Security.Cryptography.SHA256]::Create()
try {
    $rootHashBytes = $rootHash.ComputeHash([System.Text.Encoding]::UTF8.GetBytes($XamppRoot.ToLowerInvariant()))
}
finally {
    $rootHash.Dispose()
}
$rootHashText = ([System.BitConverter]::ToString($rootHashBytes)).Replace('-', '').Substring(0, 16)
$statePath = Join-Path $stateDirectory "launcher-$rootHashText.json"

if (-not (Test-Path -LiteralPath $statePath -PathType Leaf)) {
    Write-Host 'No launcher-owned XAMPP services were recorded; nothing was stopped.'
    return
}

$state = Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json
$services = @(
    @{
        Name = 'Apache'
        PidProperty = 'ApachePid'
        ProcessName = 'httpd'
        Executable = Join-Path $XamppRoot 'apache\bin\httpd.exe'
        Arguments = @('-k', 'shutdown')
    },
    @{
        Name = 'MySQL'
        PidProperty = 'MySqlPid'
        ProcessName = 'mysqld'
        Executable = Join-Path $XamppRoot 'mysql\bin\mysqladmin.exe'
        Arguments = @("--defaults-file=`"$(Join-Path $XamppRoot 'mysql\bin\my.ini')`"", '--user=root', 'shutdown')
    }
)

foreach ($service in $services) {
    $servicePid = $state.($service.PidProperty)
    if ($null -eq $servicePid) {
        Write-Host "$($service.Name) was not started by this launcher; leaving it unchanged."
        continue
    }

    $process = Get-CimInstance Win32_Process -Filter "ProcessId = $([int]$servicePid)" -ErrorAction Stop
    $expectedProcessExecutable = if ($service.ProcessName -eq 'mysqld') {
        Join-Path $XamppRoot 'mysql\bin\mysqld.exe'
    }
    else {
        $service.Executable
    }
    if (-not $process -or -not $process.ExecutablePath -or
        -not [System.String]::Equals(
            [System.IO.Path]::GetFullPath($process.ExecutablePath),
            [System.IO.Path]::GetFullPath($expectedProcessExecutable),
            [System.StringComparison]::OrdinalIgnoreCase
        )) {
        Write-Host "$($service.Name) is no longer the process started by this launcher; leaving it unchanged."
        continue
    }

    if (-not (Test-Path -LiteralPath $service.Executable -PathType Leaf)) {
        throw "Cannot stop launcher-owned $($service.Name): required executable was not found at '$($service.Executable)'."
    }

    Write-Host "Stopping launcher-owned $($service.Name)..."
    $stopCommand = Start-Process -FilePath $service.Executable -ArgumentList $service.Arguments `
        -WorkingDirectory $XamppRoot -WindowStyle Hidden -Wait -PassThru
    if ($stopCommand.ExitCode -ne 0) {
        if ($service.ProcessName -ne 'httpd') {
            throw "$($service.Name) shutdown command failed with exit code $($stopCommand.ExitCode). Its ownership record was retained."
        }

        Write-Host "Apache rejected its graceful shutdown command; stopping only the verified launcher-owned Apache process tree."
        $allApacheProcesses = @(Get-CimInstance Win32_Process -Filter "Name = 'httpd.exe'" -ErrorAction Stop)
        $ownedTree = @($process)
        $frontier = @([int]$servicePid)
        do {
            $children = @($allApacheProcesses | Where-Object {
                $frontier -contains [int]$_.ParentProcessId -and
                $_.ExecutablePath -and
                [System.String]::Equals(
                    [System.IO.Path]::GetFullPath($_.ExecutablePath),
                    [System.IO.Path]::GetFullPath($expectedProcessExecutable),
                    [System.StringComparison]::OrdinalIgnoreCase
                ) -and
                @($ownedTree | ForEach-Object { [int]$_.ProcessId }) -notcontains [int]$_.ProcessId
            })
            $frontier = @($children | ForEach-Object { [int]$_.ProcessId })
            if ($frontier.Count -gt 0) {
                $ownedTree += $children
            }
        } while ($frontier.Count -gt 0)

        for ($index = $ownedTree.Count - 1; $index -ge 0; $index--) {
            $ownedPid = [int]$ownedTree[$index].ProcessId
            $current = Get-CimInstance Win32_Process -Filter "ProcessId = $ownedPid" -ErrorAction Stop
            if ($current -and $current.ExecutablePath -and
                [System.String]::Equals(
                    [System.IO.Path]::GetFullPath($current.ExecutablePath),
                    [System.IO.Path]::GetFullPath($expectedProcessExecutable),
                    [System.StringComparison]::OrdinalIgnoreCase
                )) {
                Stop-Process -Id $ownedPid -Force
            }
        }
    }

    $deadline = (Get-Date).AddSeconds(30)
    do {
        Start-Sleep -Milliseconds 500
        $process = Get-CimInstance Win32_Process -Filter "ProcessId = $([int]$servicePid)" -ErrorAction Stop
    } while ($process -and (Get-Date) -lt $deadline)

    if ($process) {
        throw "$($service.Name) is still running after its XAMPP stop script completed. Its ownership record was retained."
    }
}

Remove-Item -LiteralPath $statePath -Force
Write-Host 'Launcher-owned services have been stopped.'
