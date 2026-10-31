import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { VitePWA } from 'vite-plugin-pwa'

// Pocket's API (server/) runs on :8000 in development; the web app only ever talks to it under /api
const API_URL = process.env.POCKET_API_URL ?? 'http://localhost:8000'

export default defineConfig({
  plugins: [
    vue(),
    VitePWA({
      strategies: 'injectManifest',
      srcDir: 'src',
      filename: 'sw.js',
      registerType: 'autoUpdate',
      injectRegister: false,
      devOptions: { enabled: false },
      manifest: {
        name: 'Firefly Pocket',
        short_name: 'Pocket',
        description: 'Your Firefly III, in your pocket',
        start_url: '/',
        scope: '/',
        display: 'standalone',
        orientation: 'portrait',
        background_color: '#d9c7b3',
        theme_color: '#d9c7b3',
        icons: [
          { src: '/icon-192.png', sizes: '192x192', type: 'image/png' },
          { src: '/icon-512.png', sizes: '512x512', type: 'image/png' },
          { src: '/icon-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
        ],
      },
      injectManifest: { globPatterns: ['**/*.{js,css,html,svg,png,woff2}'] },
    }),
  ],
  server: {
    port: 5173,
    strictPort: true,
    host: true,
    proxy: { '/api': { target: API_URL, changeOrigin: true } },
  },
  preview: { port: 4173, proxy: { '/api': { target: API_URL, changeOrigin: true } } },
})
