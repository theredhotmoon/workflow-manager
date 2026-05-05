/**
 * Docker-specific Vite config.
 *
 * In the normal dev setup, the laravel-vite-plugin writes the built assets
 * directly into ../backend/public/build so Laravel can serve them.
 *
 * In Docker the frontend is its own Nginx container, so we build a standard
 * standalone SPA into ./dist — Nginx then serves it and proxies /api/* to
 * the backend container.
 */
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
    plugins: [
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        tailwindcss(),
    ],

    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./src', import.meta.url)),
        },
    },

    build: {
        outDir:    'dist',
        emptyOutDir: true,
        // Produce a clean manifest for cache-busting
        manifest:  true,
        rollupOptions: {
            // Split vendor chunks for better caching
            output: {
                manualChunks: {
                    vue:    ['vue', 'vue-router'],
                    tiptap: [
                        '@tiptap/vue-3',
                        '@tiptap/starter-kit',
                        '@tiptap/extension-image',
                        '@tiptap/extension-link',
                        '@tiptap/extension-placeholder',
                        '@tiptap/extension-underline',
                    ],
                },
            },
        },
    },
});
