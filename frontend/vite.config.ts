import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  build: {
    outDir: 'dist',
    rollupOptions: {
      output: {
        manualChunks: {
          vendor:  ['react','react-dom','react-router-dom'],
          charts:  ['recharts'],
          state:   ['zustand'],
          http:    ['axios'],
        }
      }
    }
  },
  server: {
    port: 3000,
    proxy: { '/api': { target: 'http://localhost:8000', changeOrigin: true } }
  }
})
