import { defineConfig } from 'vite';
import { VitePWA } from 'vite-plugin-pwa';
import {readFileSync} from 'node:fs';
import {createHash} from 'node:crypto';
const imageLayout=JSON.parse(readFileSync(new URL('./docs/mobile-image-layout-2026-10-26.json',import.meta.url),'utf8'));
function shellDependencies(entries){
  const manifest=JSON.parse(readFileSync(new URL('./dist/.vite/manifest.json',import.meta.url),'utf8'));
  const urls=new Set(entries.map(entry=>entry.url)),visited=new Set();
  function visit(key){if(visited.has(key))return;visited.add(key);const chunk=manifest[key];if(!chunk)throw Error('Missing shell chunk '+key);urls.add(chunk.file);for(const css of chunk.css||[])urls.add(css);for(const imported of chunk.imports||[])visit(imported);}
  visit('index.html');
  return {manifest:[...entries,...[...urls].filter(url=>!entries.some(entry=>entry.url===url)).map(url=>({url,revision:createHash('md5').update(readFileSync(new URL('./dist/'+url,import.meta.url))).digest('hex')}))],warnings:[]};
}

export default defineConfig({
  server:{proxy:{'/api':{target:process.env.FATYA_API_PROXY||'http://127.0.0.1:3001'}}},
  resolve: {alias: [{find: /^vue$/, replacement: 'vue/dist/vue.esm-bundler.js'}]},
  build: {manifest:true},
  base: process.env.GITHUB_ACTIONS ? '/' + (process.env.GITHUB_REPOSITORY?.split('/')[1] || 'fatya') + '/' : '/',
  plugins: [VitePWA({
    registerType: 'autoUpdate',
    injectRegister: false,
    includeAssets: ['favicon-green.png', 'brand/fatya-logo-smooth.svg', 'brand/fatya-mark-smooth.svg', 'brand/fatya-logo-green.png', 'icons/apple-touch-green.png'],
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
      // Cache only the shell up front. Admin, Excel and catalog assets load on demand.
      globPatterns: ['index.html', 'assets/index-*.{js,css}', 'fonts/cairo-bold.woff2', 'icons/install-*-green.png'],
      globIgnores: ['**/middle-banners/sources/**','**/cards/sources/**',...Object.keys(imageLayout.mapping).map(src=>'**/'+src)],
      manifestTransforms:[shellDependencies],
      cleanupOutdatedCaches: true,
      skipWaiting: true,
      clientsClaim: true,
      navigateFallbackDenylist: [/^\/api\//],
      runtimeCaching: [{
        urlPattern: ({url,request}) => request.method==='GET' && url.origin===self.location.origin && !/^\/api\//.test(url.pathname) && /^\/(?:[^/]+\/)?(?:assets|fonts)\//.test(url.pathname),
        handler: 'CacheFirst',
        options: {cacheName:'fatya-public-assets-v1',cacheableResponse:{statuses:[200]},expiration:{maxEntries:160,maxAgeSeconds:2592000}},
      },{
        urlPattern: ({url,request}) => request.method==='GET' && url.origin===self.location.origin && !/^\/api\//.test(url.pathname) && /^\/(?:[^/]+\/)?(?:brand|cards|icons|middle-banners|payments)\//.test(url.pathname),
        handler: 'StaleWhileRevalidate',
        options: {cacheName:'fatya-public-images-v1',cacheableResponse:{statuses:[200]},expiration:{maxEntries:160,maxAgeSeconds:2592000}},
      }],
    },
  })],
});
