# Automatické nasazení (Deployment) - Investhor

## Plán
1. **Původní PHP backend** zůstane na `hollyhop.cz/broker`.
2. **Nový React frontend** (Investhor) bude na `hollyhop.cz/investhor`.
3. Využijeme GitHub Actions pro automatické sestavení a nahrání obou částí na FTP.

## Jak na to
Vytvoř/uprav soubor `.github/workflows/deploy.yml` v kořenové složce repozitáře (`hollyhop/.github/workflows/Deploy.yml`).

```yaml
name: Deploy HollyHop (Broker & Investhor)

on:
  push:
    branches:
      - main

jobs:
  # 1. Deploy Legacy PHP Backend
  deploy-backend:
    name: Deploy PHP Backend (/broker)
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Sync PHP files to FTP
        uses: SamKirkland/FTP-Deploy-Action@v4.3.5
        with:
          server: ${{ secrets.FTP_SERVER }}
          username: ${{ secrets.FTP_USERNAME }}
          password: ${{ secrets.FTP_PASSWORD }}
          local-dir: ./broker/broker 2.0/
          server-dir: /www/domains/hollyhop.cz/broker/broker 2.0/ 
          exclude: |
            **/.git*
            **/node_modules/**

  # 2. Build & Deploy React Frontend
  deploy-frontend:
    name: Deploy React Investhor (/investhor)
    runs-on: ubuntu-latest
    needs: deploy-backend # Optional: run in parallel if you remove this
    steps:
      - uses: actions/checkout@v4
      
      - name: Setup Node
        uses: actions/setup-node@v4
        with:
          node-version: '18'
          
      - name: Install & Build Investhor
        # Go to client folder, install deps, and build
        run: |
          cd broker/broker-client
          npm install
          npm run build
          
      - name: Sync React Build to FTP
        uses: SamKirkland/FTP-Deploy-Action@v4.3.5
        with:
            server: ${{ secrets.FTP_SERVER }}
            username: ${{ secrets.FTP_USERNAME }}
            password: ${{ secrets.FTP_PASSWORD }}
            # Upload the contents of 'dist' folder
            local-dir: ./broker/broker-client/dist/
            server-dir: /www/domains/hollyhop.cz/investhor/
```

### Hotovo!
Jakmile tento soubor vložíš na GitHub, každá změna se automaticky:
1. Promítne do starého PHP webu.
2. Zkompiluje nový React web a nahraje ho na `/investhor`.
