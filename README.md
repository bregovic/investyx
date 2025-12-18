# Investyx Trading App

A comprehensive trading and portfolio management application.

## 🚀 Features
- **Portfolio Management**: Track stocks, dividends, and P&L.
- **Market Data**: Real-time quotes and data analysis.
- **Helpdesk**: Integrated request management system.
- **Import**: Tools for importing data from various brokers (Fio, Revolut, etc.).

## 🛠 Tech Stack
- **Frontend**: React, TypeScript, Vite, Fluent UI
- **Backend**: Native PHP (API)
- **Database**: MySQL

## 📦 structure
- `broker-client/` - React frontend application.
- `broker 2.0/` - PHP backend API and legacy scripts.
- `.github/workflows/` - CI/CD pipelines (Auto-deploy to FTP).

## 🚦 Getting Started
### Frontend
1. Navigate to `broker-client`
2. `npm install`
3. `npm run dev`

### Deployment
Automatic deployment via GitHub Actions on push to `main`.
- **Version**: Automatically generated as `YYMMDD.Rev` (e.g., 251218.1)
