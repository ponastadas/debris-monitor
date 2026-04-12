import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  test: {
    globals: true,
    environment: 'jsdom',
    setupFiles: './src/test/setup.js',
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    hmr: {
      clientPort: 5173,
    },
    watch: {
      // Polling is required on WSL2 /mnt/c paths where inotify doesn't fire
      usePolling: true,
      interval: 300,
    },
    proxy: {
      '/api': {
        // In Docker: BACKEND_URL=http://backend:8000 (set via docker-compose)
        // Outside Docker: falls back to localhost:8000
        target: process.env.BACKEND_URL ?? 'http://localhost:8000',
        changeOrigin: true,
      },
    },
  }
})
