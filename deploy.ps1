
# Deploy Script for HollyHop
# Automatically updates GitHub Actions workflow and pushes changes

$ErrorActionPreference = "Stop"

# 1. Define Paths
$scriptDir = $PSScriptRoot
$repoRoot = Resolve-Path "$scriptDir/.."
$workflowSource = "$scriptDir/deploy-workflow.yml"
$workflowDestDir = "$repoRoot/.github/workflows"
$workflowDest = "$workflowDestDir/deploy.yml"

Write-Host "🚀 Spouštím nasazovací skript pro HollyHop..." -ForegroundColor Cyan

# 2. Check/Instal Workflow File
if (Test-Path $workflowSource) {
    Write-Host "Checking GitHub Action configuration..."
    if (!(Test-Path $workflowDestDir)) {
        New-Item -ItemType Directory -Force -Path $workflowDestDir | Out-Null
        Write-Host "Created .github/workflows directory."
    }
    Copy-Item -Path $workflowSource -Destination $workflowDest -Force
    Write-Host "✅ Workflow file updated at: .github/workflows/deploy.yml" -ForegroundColor Green
} else {
    Write-Warning "Workflow source file not found in broker folder. Skipping workflow update."
}

# 3. Git Operations
Set-Location $repoRoot

Write-Host "Checking Git status..."
git status -s

$confirm = Read-Host "Chceš pokračovat s pushnutím změn? (a/n)"
if ($confirm -ne 'a') {
    Write-Host "Ukončeno."
    exit
}

Write-Host "Staging changes..."
git add .

$msg = Read-Host "Zadej commit zprávu (Enter pro 'Update')"
if ([string]::IsNullOrWhiteSpace($msg)) { $msg = "Update" }

Write-Host "Committing..."
git commit -m "$msg"

Write-Host "Pushing to GitHub..."
git push origin main

if ($?) {
    Write-Host "✅ HOTOVO! Změny jsou na GitHubu." -ForegroundColor Green
    Write-Host "GitHub Action nyní automaticky nasadí web na FTP (Investhor & Broker)." -ForegroundColor Gray
} else {
    Write-Error "Chyba při pushování."
}

Pause
