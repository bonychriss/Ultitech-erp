import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// Local XAMPP: /mail/app/
// Live: https://ultimate.co.tz/staff/mail/frontend/web/ → npm run build:live
const base =
  process.env.VITE_BASE_PATH ||
  (process.env.npm_lifecycle_event === 'build:live'
    ? '/staff/mail/frontend/web/app/'
    : '/mail/app/')

export default defineConfig({
  plugins: [react()],
  base,
  build: {
    outDir: '../web/app',
    emptyOutDir: true,
  },
  server: {
    port: 5173,
    proxy: {
      '/mail/api': {
        target: 'http://localhost',
        changeOrigin: true,
      },
    },
  },
})
