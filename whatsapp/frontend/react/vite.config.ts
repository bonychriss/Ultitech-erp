import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

const base = process.env.VITE_BASE_PATH || '/public_html/whatsapp/frontend/web/app/'

export default defineConfig({
  plugins: [react()],
  base,
  build: {
    outDir: '../web/app',
    emptyOutDir: true,
  },
  server: {
    port: 5174,
  },
})
