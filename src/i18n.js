import {routePath,inRoute} from './routing.js';
import {ref,watch} from 'vue/dist/vue.esm-bundler.js';
import english from './translations-en.js';
import kurdish from './translations-ku.js';
export const language=ref('ar');
try{const saved=localStorage.getItem('masal-language');if(['ar','ku','en'].includes(saved))language.value=saved;}catch{}
export function t(value){const text=String(value??'');return language.value==='ku'?(kurdish[text]??text):language.value==='en'?(english[text]??text):text;}
export function denominations(count){return language.value==='ku'?`${count} بەهای بەردەست`:language.value==='en'?`${count} denomination${count===1?'':'s'} available`:`${count} فئات متوفرة`;}
export function cardCount(count){return language.value==='ku'?`${count} کارت`:language.value==='en'?`${count} card${count===1?'':'s'}`:`${count} بطاقات`;}
export function setLanguage(value){language.value=['ar','ku','en'].includes(value)?value:'ar';}
export function applyLanguage(){const storeRoute=!inRoute(routePath(),'/admin')&&!inRoute(routePath(),'/ar');const locale=storeRoute?language.value:'ar';document.documentElement.lang=locale==='ku'?'ckb':locale;document.documentElement.dir=locale==='en'?'ltr':'rtl';if(storeRoute)document.title=locale==='en'?'Fatya':locale==='ku'?'فاتیا':'فاتيا';}
applyLanguage();watch(language,()=>{applyLanguage();try{localStorage.setItem('masal-language',language.value);}catch{}},{flush:'sync'});window.addEventListener('hashchange',applyLanguage);

window.addEventListener('fatya:routechange',applyLanguage);
