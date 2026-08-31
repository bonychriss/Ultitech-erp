import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  base: './',
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    rollupOptions: {
      output: {
        entryFileNames: 'assets/backup-ui.js',
        chunkFileNames: 'assets/backup-ui-[name].js',
        assetFileNames: 'assets/backup-ui[extname]',
      },
    },
  },
});
