import {readFileSync,readdirSync} from 'node:fs';
import {join} from 'node:path';
import {createRequire} from 'node:module';
import {spawnSync} from 'node:child_process';
const require=createRequire(import.meta.url),vueRequire=createRequire(require.resolve('vue/package.json'));
const {compile}=vueRequire('@vue/compiler-dom');
const copy=JSON.parse(readFileSync('src/marketing/copy.json','utf8'));
let files=0,templates=0;
function scan(folder){for(const item of readdirSync(folder,{withFileTypes:true})){const file=join(folder,item.name);if(item.isDirectory()){scan(file);continue}if(!file.endsWith('.js'))continue;files++;const source=readFileSync(file,'utf8'),syntax=spawnSync(process.execPath,['--check',file],{encoding:'utf8'});if(syntax.status!==0)throw Error(syntax.stderr);for(const match of source.matchAll(/template:\s*`([\s\S]*?)`/g)){const compiled=compile(match[1],{mode:'function',onError(error){throw Error(`${file}: ${error.message}`)}});try{new Function('Vue',compiled.code);}catch(error){throw Error(`${file}: invalid generated render function: ${error.message}`);}templates++}if(folder.includes('marketing'))for(const match of source.matchAll(/copy\('([^']+)'\)/g))if(!copy.some(s=>s.startsWith(match[1])))throw Error(`${file}: unknown reference text ${match[1]}`)}}
scan('src');
console.log(`Checked ${files} JavaScript files, ${templates} Vue templates, and all reference text lookups.`);
