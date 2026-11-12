import { defineConfig, loadEnv } from 'vite'
import process from 'node:process'
import react from '@vitejs/plugin-react'

export default defineConfig(({ mode }) => ({
  plugins: [react()],
  server: {
    host: '0.0.0.0',
    port: 5173,
    allowedHosts: true,
    proxy: {
      '/api': {
        // Match the IPv4 address used by start_all.bat. Override on the server,
        // never by embedding a backend origin in browser requests.
        target: loadEnv(mode, process.cwd(), '').API_PROXY_TARGET || 'http://127.0.0.1:8000',
        changeOrigin: true,
        secure: false,
        configure(proxy) {
          proxy.on('error', (_error, _request, response) => {
            if (typeof response.writeHead !== 'function' || response.headersSent) return
            response.writeHead(503, { 'Content-Type': 'application/json' })
            response.end(JSON.stringify({ message: 'API service is unavailable.', code: 'api_unavailable' }))
          })
        },
      }
    }
  }
}))
