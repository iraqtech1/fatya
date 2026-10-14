import {ref,watch} from 'vue/dist/vue.esm-bundler.js';
import english from './translations-en.js';
export const language=ref('ar');
try{if(localStorage.getItem('masal-language')==='en')language.value='en';}catch{}
export function t(value){const text=String(value??'');return language.value==='en'?(english[text]??text):text;}
export function denominations(count){return language.value==='en'?`${count} denomination${count===1?'':'s'} available`:`${count} فئات متوفرة`;}
export function cardCount(count){return language.value==='en'?`${count} card${count===1?'':'s'}`:`${count} بطاقات`;}
export function setLanguage(value){language.value=value==='en'?'en':'ar';}
export function applyLanguage(){const storeRoute=!location.hash.startsWith('#/admin')&&!location.hash.startsWith('#/ar');const locale=storeRoute?language.value:'ar';document.documentElement.lang=locale;document.documentElement.dir=locale==='en'?'ltr':'rtl';if(storeRoute)document.title=locale==='en'?'Fatya':'فاتيا';}
applyLanguage();watch(language,()=>{applyLanguage();try{localStorage.setItem('masal-language',language.value);}catch{}},{flush:'sync'});window.addEventListener('hashchange',applyLanguage);
