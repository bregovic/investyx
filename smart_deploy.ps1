# Helper script to run SmartDeployer for this project
$Config = "$PSScriptRoot/deploy/broker-config.json"
$Deployer = "$PSScriptRoot/deploy/SmartDeployer.ps1"

powershell -ExecutionPolicy Bypass -File $Deployer -ConfigPath $Config -Env "prod" @args
