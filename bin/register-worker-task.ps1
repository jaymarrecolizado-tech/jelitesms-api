# Registers a Windows Task Scheduler job that runs the SMS queue worker
# every minute. Paths are derived from this script's location, so it keeps
# working if the project folder moves (re-run after moving).
#
# Usage (from an elevated or normal PowerShell):
#   powershell -ExecutionPolicy Bypass -File bin\register-worker-task.ps1
#
# Optional parameters:
#   -TaskName "JE Lite SMS Worker"   # scheduled task name
#   -PhpPath    "C:\xampp\php\php.exe"
#
# Remove the task later with:
#   Unregister-ScheduledTask -TaskName "JE Lite SMS Worker" -Confirm:$false

param(
    [string]$TaskName = "JE Lite SMS Worker",
    [string]$PhpPath = "C:\xampp\php\php.exe"
)

$ErrorActionPreference = "Stop"

$projectDir = Split-Path -Parent $PSScriptRoot
$worker = Join-Path $projectDir "bin\worker.php"

if (-not (Test-Path $worker)) {
    throw "Worker script not found: $worker"
}
if (-not (Test-Path $PhpPath)) {
    throw "PHP not found at '$PhpPath'. Pass -PhpPath with the correct path."
}

$action = New-ScheduledTaskAction -Execute $PhpPath -Argument "`"$worker`"" -WorkingDirectory $projectDir

# Every minute, indefinitely (10-year window is the practical max for task XML).
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) `
    -RepetitionInterval (New-TimeSpan -Minutes 1) `
    -RepetitionDuration (New-TimeSpan -Days 3650)

$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -MultipleInstances IgnoreNew `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 5)

Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Force | Out-Null

Write-Host "Scheduled task '$TaskName' registered."
Write-Host "  PHP:   $PhpPath"
Write-Host "  Worker: $worker"
Write-Host "  Runs:  every minute"
Write-Host ""
Write-Host "Run now to verify:  Start-ScheduledTask -TaskName '$TaskName'"
Write-Host "Remove later:       Unregister-ScheduledTask -TaskName '$TaskName' -Confirm:`$false"
