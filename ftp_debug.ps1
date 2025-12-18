param(
    [string]$FtpHost,
    [string]$Username,
    [string]$Password
)

$url = "ftp://$FtpHost/"
$req = [System.Net.WebRequest]::Create($url)
$req.Method = [System.Net.WebRequestMethods+Ftp]::ListDirectoryDetails
$req.Credentials = New-Object System.Net.NetworkCredential($Username, $Password)

try {
    $response = $req.GetResponse()
    $reader = New-Object System.IO.StreamReader($response.GetResponseStream())
    $content = $reader.ReadToEnd()
    $reader.Close()
    $response.Close()
    Write-Host "--- FTP ROOT LISTING ---"
    Write-Host $content
}
catch {
    Write-Error $_
}
