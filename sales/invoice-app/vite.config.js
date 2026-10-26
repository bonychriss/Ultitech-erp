import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  base: '/projects/invoice/',
  build: {
    outDir: '../invoice',
    emptyOutDir: true,
  },
})
