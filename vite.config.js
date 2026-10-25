import { defineConfig } from 'vite';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
  server:{proxy:{'/api':{target:process.env.FATYA_API_PROXY||'http://127.0.0.1:3001'}}},
  resolve: {alias: [{find: /^vue$/, replacement: 'vue/dist/vue.esm-bundler.js'}]},
  base: process.env.GITHUB_ACTIONS ? '/' + (process.env.GITHUB_REPOSITORY?.split('/')[1] || 'fatya') + '/' : '/',
  plugins: [VitePWA({
    registerType: 'autoUpdate',
    injectRegister: false,
    includeAssets: ['favicon-green.png', 'brand/*', 'icons/*.png', 'fonts/*.ttf'],
    manifest: {
      id: './', name: 'فاتيا — بطاقاتك الرقمية', short_name: 'فاتيا',
      description: 'بطاقات الرصيد والألعاب والبطاقات العالمية بمكان واحد.',
      lang: 'ar', dir: 'rtl', start_url: './', scope: './',
      display: 'standalone', background_color: '#061a14', theme_color: '#0a8b61',
      icons: [
        {src: 'icons/install-192-green.png', sizes: '192x192', type: 'image/png', purpose: 'any'},
        {src: 'icons/install-512-green.png', sizes: '512x512', type: 'image/png', purpose: 'any'},
        {src: 'icons/install-512-green.png', sizes: '512x512', type: 'image/png', purpose: 'maskable'},
      ],
    },
    workbox: {
      globPatterns: ['**/*.{js,css,html,svg,png,webp,ttf}'],
      cleanupOutdatedCaches: true,
      skipWaiting: true,
      clientsClaim: true,
      navigateFallbackDenylist: [/^\/api\//],
    },
  })],
});
