import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { execSync } from 'child_process';

const commitHash = execSync('git rev-parse --short HEAD').toString().trim();
const buildDate = new Date().toISOString();

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [react()],
  base: '/investyx/', // Deploy to hollyhop.cz/investyx
  define: {
    __APP_VERSION__: JSON.stringify(commitHash),
    __APP_BUILD_DATE__: JSON.stringify(buildDate)
  },
  server: {
    port: 5173
  }
})
