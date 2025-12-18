# SmartDeployer - Univerzální nasazovací systém

Tento systém umožňuje chytré a rychlé nasazování projektů na FTP. Hlavní výhodou je, že nahrává **pouze změněné soubory**.

## Jak to funguje
Skript vypočítá MD5 hash každého souboru a porovná ho s cache z minulého nasazení (`.deploy-cache-[projekt].json`). Pokud se soubor nezměnil, přeskočí ho.

## Součásti
- `SmartDeployer.ps1`: Jádro systému (univerzální skript).
- `broker-config.json`: Konfigurace pro tento konkrétní projekt.
- `smart_deploy.ps1`: Pomocný spouštěč v kořenovém adresáři.

## Jak použít pro jiný projekt
1. Zkopírujte složku `deploy/` do nového projektu.
2. Vytvořte si vlastní `.json` konfiguraci (např. `my-project.json`).
3. Spusťte nasazení:
   ```powershell
   powershell -ExecutionPolicy Bypass -File deploy/SmartDeployer.ps1 -ConfigPath deploy/my-project.json
   ```

## Parametry SmartDeployer.ps1
- `-ConfigPath`: Cesta k JSON konfiguraci (povinné).
- `-Env`: Název prostředí z JSONu (výchozí "prod").
- `-ForceUpload`: Vynutí nahrání všech souborů i bez změny.
- `-DryRun`: Pouze vypíše, co by se nahrálo, ale nic neprovede.

## Konfigurace (JSON)
- `mappings`: Seznam složek k nahrání (místní -> vzdálená).
- `exclude`: Seznam regexů pro soubory, které se mají přeskočit (např. `.git`, `node_modules`).
- `scripts.build`: Příkaz, který se spustí před nasazením (např. `npm run build`).
- `web_triggers`: Seznam URL, které se mají po nasazení "prokliknout" (např. pro spuštění databázových migrací).
