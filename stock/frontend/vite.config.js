import { defineConfig } from 'vite'
import path from 'path'

// Note: omitting @vitejs/plugin-react to avoid ESM import/runtime issues
// This build will rely on esbuild for JSX transformation which is sufficient
// for this small app. For full React Fast Refresh support, install the plugin
// and run the dev server in an environment that supports ESM.
export default defineConfig({
  plugins: [],
  root: path.resolve(__dirname, 'src'),
  build: {
    outDir: path.resolve(__dirname, 'dist'),
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: path.resolve(__dirname, 'src', 'main.jsx')
    }
  },
  esbuild: {
    jsxFactory: 'React.createElement',
    jsxFragment: 'React.Fragment'
  }
})
