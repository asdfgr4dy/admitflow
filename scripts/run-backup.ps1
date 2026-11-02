[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('daily', 'weekly')]
    [string] $Schedule
)

$projectRoot = Split-Path -Parent $PSScriptRoot
$runnerPath = Join-Path $PSScriptRoot 'backup-runner.php'
$phpExecutable = $env:ADMITFLOW_PHP_EXECUTABLE
if ([string]::IsNullOrWhiteSpace($phpExecutable)) {
    $phpExecutable = 'C:\Users\Administrator\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.2_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe'
}

if (-not (Test-Path -LiteralPath $phpExecutable -PathType Leaf)) {
    [Console]::Error.WriteLine('Configured PHP executable was not found.')
    exit 2
}
if (-not (Test-Path -LiteralPath $runnerPath -PathType Leaf)) {
    [Console]::Error.WriteLine('AdmitFlow backup runner was not found.')
    exit 2
}

$phpVersionText = [string](& $phpExecutable -r 'echo PHP_VERSION;' 2>$null)
$phpVersionExitCode = $LASTEXITCODE
$phpVersion = $null
if ($phpVersionExitCode -ne 0 -or
    -not [version]::TryParse($phpVersionText, [ref]$phpVersion) -or
    $phpVersion -lt [version]'8.2') {
    [Console]::Error.WriteLine('Configured PHP executable must be version 8.2 or later.')
    exit 2
}

Push-Location -LiteralPath $projectRoot
try {
    & $phpExecutable $runnerPath $Schedule '--source=scheduled'
    exit $LASTEXITCODE
}
finally {
    Pop-Location
}
