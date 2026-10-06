/**
 * Hybrid dev mode Vite config (see ../dev.ps1 and ../docker-compose.dev.yml).
 *
 * db + backend run in Docker, the SPA runs here on http://localhost:5173.
 * Builds on the standalone-SPA Docker config rather than vite.config.js,
 * because laravel-vite-plugin replaces index.html with its own placeholder
 * page when the dev server is opened directly.
 *
 * The proxy mirrors the same-origin setup of the prod frontend Nginx
 * container (frontend/docker/nginx.conf), so Sanctum's cookie auth works
 * without CORS.
 */
import { defineConfig, mergeConfig } from 'vite';
import dockerConfig from './vite.config.docker.js';

const backend = process.env.BACKEND_URL || 'http://localhost:8090';

export default mergeConfig(
  dockerConfig,
  defineConfig({
    server: {
      port: 5173,
      strictPort: true,
      proxy: {
        '/api': { target: backend, changeOrigin: true },
        '/sanctum': { target: backend, changeOrigin: true },
      },
    },
  }),
);
