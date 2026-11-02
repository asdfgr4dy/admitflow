[CmdletBinding(SupportsShouldProcess = $true, ConfirmImpact = 'High')]
param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('Register', 'Review', 'Enable', 'Disable', 'Remove')]
    [string] $Action,
    [ValidatePattern('^([01]\d|2[0-3]):[0-5]\d$')]
    [string] $DailyTime = '01:00',
    [ValidateSet('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')]
    [string] $WeeklyDay = 'Sunday',
    [ValidatePattern('^([01]\d|2[0-3]):[0-5]\d$')]
    [string] $WeeklyTime = '02:00'
)

$dailyTaskName = 'AdmitFlow Daily Backup'
$weeklyTaskName = 'AdmitFlow Weekly Recovery Checkpoint'
$taskNames = @($dailyTaskName, $weeklyTaskName)
$projectRoot = Split-Path -Parent $PSScriptRoot
$wrapperPath = Join-Path $PSScriptRoot 'run-backup.ps1'
$powerShellPath = Join-Path $PSHOME 'powershell.exe'

switch ($Action) {
    'Register' {
        $principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" -LogonType S4U -RunLevel Limited
        $settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Hours 12) -Disable
        $dailyTrigger = New-ScheduledTaskTrigger -Daily -At ([datetime]::ParseExact($DailyTime, 'HH:mm', [Globalization.CultureInfo]::InvariantCulture))
        $weeklyTrigger = New-ScheduledTaskTrigger -Weekly -DaysOfWeek $WeeklyDay -At ([datetime]::ParseExact($WeeklyTime, 'HH:mm', [Globalization.CultureInfo]::InvariantCulture))
        foreach ($task in @(@{ Name = $dailyTaskName; Schedule = 'daily'; Trigger = $dailyTrigger }, @{ Name = $weeklyTaskName; Schedule = 'weekly'; Trigger = $weeklyTrigger })) {
            $arguments = '-NoProfile -NonInteractive -File "{0}" -Schedule {1}' -f $wrapperPath, $task.Schedule
            $taskAction = New-ScheduledTaskAction -Execute $powerShellPath -Argument $arguments -WorkingDirectory $projectRoot
            if ($PSCmdlet.ShouldProcess($task.Name, 'Register Windows Scheduled Task')) {
                Register-ScheduledTask -TaskName $task.Name -Action $taskAction -Trigger $task.Trigger -Settings $settings -Principal $principal | Out-Null
                Write-Output "Registered: $($task.Name)"
            }
        }
    }
    'Review' {
        foreach ($taskName in $taskNames) {
            $task = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
            if ($null -eq $task) {
                Write-Output "Not registered: $taskName"
                continue
            }
            $info = Get-ScheduledTaskInfo -TaskName $taskName
            [pscustomobject]@{
                TaskName = $task.TaskName
                State = $task.State
                LastRunTime = $info.LastRunTime
                LastTaskResult = $info.LastTaskResult
                NextRunTime = $info.NextRunTime
                RunAsUser = $task.Principal.UserId
                LogonType = $task.Principal.LogonType
            }
        }
    }
    'Enable' {
        foreach ($taskName in $taskNames) {
            if ($PSCmdlet.ShouldProcess($taskName, 'Enable Windows Scheduled Task')) { Enable-ScheduledTask -TaskName $taskName -ErrorAction Stop | Out-Null }
        }
    }
    'Disable' {
        foreach ($taskName in $taskNames) {
            if ($PSCmdlet.ShouldProcess($taskName, 'Disable Windows Scheduled Task')) { Disable-ScheduledTask -TaskName $taskName -ErrorAction Stop | Out-Null }
        }
    }
    'Remove' {
        foreach ($taskName in $taskNames) {
            if (Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue) {
                if ($PSCmdlet.ShouldProcess($taskName, 'Remove Windows Scheduled Task')) { Unregister-ScheduledTask -TaskName $taskName -Confirm:$false -ErrorAction Stop }
            }
        }
    }
}
