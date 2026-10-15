$ErrorActionPreference = "Stop"

$projectRoot = Split-Path -Parent $PSScriptRoot
$envPath = Join-Path $projectRoot ".env"
$examplePath = Join-Path $projectRoot ".env.example"

Write-Host "Somame AI local setup"
Write-Host "====================="

if (-not (Test-Path $envPath)) {
    if (Test-Path $examplePath) {
        Copy-Item $examplePath $envPath
    } else {
        New-Item -ItemType File -Path $envPath | Out-Null
    }
}

$secureKey = Read-Host "Paste your OpenAI API key (input hidden)" -AsSecureString
$ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureKey)

try {
    $apiKey = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)
}
finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)
}

if ([string]::IsNullOrWhiteSpace($apiKey)) {
    throw "API key cannot be empty."
}

$lines = @()

if (Test-Path $envPath) {
    $lines = Get-Content $envPath
}

function Set-EnvValue {
    param(
        [string[]] $InputLines,
        [string] $Name,
        [string] $Value
    )

    $found = $false
    $output = foreach ($line in $InputLines) {
        if ($line -match ("^" + [regex]::Escape($Name) + "=")) {
            $found = $true
            "$Name=$Value"
        } else {
            $line
        }
    }

    if (-not $found) {
        $output += "$Name=$Value"
    }

    return ,$output
}

$lines = Set-EnvValue $lines "OPENAI_API_KEY" $apiKey
$lines = Set-EnvValue $lines "OPENAI_MODEL" "gpt-6-luna"
$lines = Set-EnvValue $lines "OPENAI_BASE_URL" "https://api.openai.com/v1"

Set-Content -Path $envPath -Value $lines -Encoding UTF8

Write-Host ""
Write-Host "[OK] .env updated locally."
Write-Host "[OK] Model set to gpt-6-luna."
Write-Host "[OK] .env is ignored by Git."
Write-Host ""
Write-Host "Next:"
Write-Host "  php scripts\test_ai_connection.php"
Write-Host ""
Write-Host "After the connection test passes, enable AI from:"
Write-Host "  Super Admin > Settings > AI Assistant"
