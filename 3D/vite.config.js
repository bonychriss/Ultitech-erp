import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  // Relative base so packaged Electron (file://) and static hosting both resolve assets correctly.
  base: './',
  server: {
    host: true,
    port: 5180,
    strictPort: true
  }
})
