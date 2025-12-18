param(
    [Parameter(Mandatory = $true)]
    [string]$ConfigPath,
    [string]$Env = "prod",
    [switch]$ForceUpload,
    [switch]$DryRun
)

$ErrorActionPreference = "Stop"

if (!(Test-Path $ConfigPath)) { throw "Config file not found" }
$Config = Get-Content $ConfigPath | ConvertFrom-Json
$ActiveEnv = $Config.environments.$Env
if (!$ActiveEnv) { throw "Environment not found" }

$ProjSafe = $Config.project_name -replace '[^a-zA-Z0-9_]', '_'
$CacheFile = Join-Path $PSScriptRoot (".deploy-cache-" + $ProjSafe + ".json")
$Cache = @{}

if (Test-Path $CacheFile) {
    try { 
        $json = Get-Content $CacheFile -Raw
        $Cache = $json | ConvertFrom-Json -AsHashtable 
    }
    catch { }
}

$Global:UploadCount = 0
$Global:SkipCount = 0

function Get-FileHashMD5($p) {
    if (!(Test-Path $p)) { return "0" }
    $md5 = [System.Security.Cryptography.MD5]::Create()
    $s = [System.IO.File]::OpenRead($p)
    $h = [System.BitConverter]::ToString($md5.ComputeHash($s)) -replace '-'
    $s.Close()
    return $h
}

function Create-Dir($u, $c) {
    try {
        $r = [System.Net.WebRequest]::Create($u)
        $r.Method = [System.Net.WebRequestMethods+Ftp]::MakeDirectory
        $r.Credentials = $c
        $r.GetResponse().Close()
    }
    catch { }
}

function Do-Upload($l, $u, $c) {
    if ($Global:DryRun_Internal) {
        Write-Host "DRY: $u"
        return
    }
    $w = New-Object System.Net.WebClient
    $w.Credentials = $c
    try {
        $w.UploadFile($u, $l) | Out-Null
        $Global:UploadCount++
    }
    finally { $w.Dispose() }
}

$Global:DryRun_Internal = $DryRun

if ($Config.scripts.build) {
    Write-Host "Building..."
    $cwd = Join-Path $PSScriptRoot ".."
    if ($Config.scripts.build.cwd) { $cwd = Resolve-Path (Join-Path $cwd $Config.scripts.build.cwd) }
    Push-Location $cwd
    Invoke-Expression $Config.scripts.build.cmd
    Pop-Location
}

$Creds = New-Object System.Net.NetworkCredential($ActiveEnv.ftp.user, $ActiveEnv.ftp.pass)
$NewCache = @{}

foreach ($Mapping in $ActiveEnv.mappings) {
    $lp = Join-Path $PSScriptRoot ".."
    $lp = Join-Path $lp $Mapping.local
    $flp = (Resolve-Path $lp).Path
    $rb = "$($ActiveEnv.ftp.host)$($ActiveEnv.ftp.remote_root)$($Mapping.remote)"
    if ($rb -notmatch "^ftp://") { $rb = "ftp://$rb" }

    Write-Host "Mapping: $($Mapping.local)"

    $Files = Get-ChildItem -Path $flp -Recurse -File
    foreach ($File in $Files) {
        $rp = $File.FullName.Substring($flp.Length).TrimStart('\').Replace('\', '/')
        if ([string]::IsNullOrWhiteSpace($rp)) { $rp = $File.Name }
        
        $exc = $false
        foreach ($Ex in $ActiveEnv.exclude) {
            if ($rp -match [regex]::Escape($Ex)) { $exc = $true; break }
        }
        if ($exc) { continue }

        $h = Get-FileHashMD5 $File.FullName
        $k = "$($Mapping.local)/$rp"
        $NewCache[$k] = $h

        if ($ForceUpload -or ($Cache[$k] -ne $h)) {
            Write-Host "UP: $rp"
            $parts = $rp.Split('/')
            if ($parts.Length -gt 1) {
                $curr = $rb
                for ($i = 0; $i -lt ($parts.Length - 1); $i++) {
                    $curr = "$curr/$($parts[$i])"
                    Create-Dir $curr $Creds
                }
            }
            Do-Upload $File.FullName "$rb/$rp" $Creds
        }
        else {
            $Global:SkipCount++
        }
    }
}

if (!$DryRun) {
    $NewCache | ConvertTo-Json | Set-Content $CacheFile
}

Write-Host "Done"
Write-Host "Uploaded: $Global:UploadCount"
Write-Host "Skipped: $Global:SkipCount"
