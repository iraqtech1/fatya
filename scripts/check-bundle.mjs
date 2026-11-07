import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {gzipSync} from 'node:zlib';
const dist=path.resolve('dist'),manifest=JSON.parse(fs.readFileSync(path.join(dist,'.vite/manifest.json'))),sw=fs.readFileSync(path.join(dist,'sw.js'),'utf8');
const precached=new Set([...sw.matchAll(/url:"([^"]+)"/g)].map(match=>match[1]));
const visited=new Set(),files=new Set();
function visit(key){if(visited.has(key))return;visited.add(key);const chunk=manifest[key];files.add(chunk.file);assert.ok(precached.has(chunk.file),'Offline shell misses '+chunk.file);for(const css of chunk.css||[])assert.ok(precached.has(css),'Offline shell misses '+css);for(const child of chunk.imports||[])visit(child);}
visit('index.html');
assert.ok(!visited.has('src/Dashboard.js')&&!visited.has('src/marketing/Home.js'),'Admin and marketing must remain deferred');
let initialJS=0,initialGzip=0,precacheBytes=0;
for(const file of files){const bytes=fs.readFileSync(path.join(dist,file));initialJS+=bytes.length;initialGzip+=gzipSync(bytes).length;}
for(const file of precached)precacheBytes+=fs.statSync(path.join(dist,file)).size;
assert.ok(initialJS<=550000,'Initial JavaScript exceeds 550kB');assert.ok(initialGzip<=185000,'Initial gzip JavaScript exceeds 185kB');
assert.ok(precacheBytes<=2600*1024,'PWA shell and official identity assets exceed 2.6MiB');
assert.ok(![...precached].some(file=>/Dashboard-|exceljs\.|\.ttf$/.test(file)),'Deferred admin, Excel or legacy fonts must not be downloaded on installation');
assert.ok(fs.statSync(path.join(dist,'fonts/cairo-bold.woff2')).size<40000);
console.log('Bundle budgets and complete offline shell passed: '+JSON.stringify({initialJS,initialGzip,precacheBytes,precacheFiles:precached.size}));
