import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import english from '../src/translations-en.js';
const saved=new Map([['masal-language','en']]);
globalThis.localStorage={getItem:key=>saved.get(key)??null,setItem:(key,value)=>saved.set(key,value)};
globalThis.document={documentElement:{},createElement:()=>({}),title:''};
globalThis.location={hash:'#/'};
const events=new Map();globalThis.window={addEventListener:(name,callback)=>events.set(name,callback)};
const {t,setLanguage,language,denominations,cardCount}=await import('../src/i18n.js');
assert.equal(language.value,'en');assert.equal(document.documentElement.dir,'ltr');assert.equal(document.documentElement.lang,'en');
assert.equal(cardCount(1),'1 card');assert.equal(cardCount(2),'2 cards');assert.equal(denominations(1),'1 denomination available');assert.equal(t('طلباتي'),'My orders');assert.equal(t('اكتب اسمك الكامل.'),'Enter your full name.');
assert.equal(t('Custom user content'),'Custom user content','Custom content must be preserved');
setLanguage('ar');assert.equal(document.documentElement.dir,'rtl');assert.equal(t('طلباتي'),'طلباتي');assert.equal(saved.get('masal-language'),'ar');
setLanguage('invalid');assert.equal(language.value,'ar');
setLanguage('en');assert.equal(saved.get('masal-language'),'en');
location.hash='#/admin/overview';events.get('hashchange')();assert.equal(document.documentElement.dir,'rtl','Dashboard direction stays Arabic');
location.hash='#/';events.get('hashchange')();assert.equal(document.documentElement.dir,'ltr','Store restores chosen language');
const reload=await import('../src/i18n.js?restore');assert.equal(reload.language.value,'en');
for(const file of ['main','AccountPage','AuthGate','StoreHeader','CardDeck','BannerSlider','MobileDock','PullToRefresh','PwaControls','SplashIntro','ThemeToggle']){
 const source=readFileSync('src/'+file+'.js','utf8').replaceAll('&quot;','"');
 for(const match of source.matchAll(/\bt\(["']([^"']+)["']\)/g))if(/[\u0600-\u06ff]/.test(match[1]))assert.ok(english[match[1]],file+': missing translation '+match[1]);
}
console.log('Language switching, persistence, direction, custom content and UI translation coverage checks passed.');
