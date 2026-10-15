import {t,language} from './i18n.js';
import {ref,watch} from 'vue/dist/vue.esm-bundler.js';
import ThemeScene from './ThemeScene.js';
export const dark=ref(false);
export const dashboardDark=ref(false);
try{dark.value=localStorage.getItem('masal-theme')==='dark';}catch{}
try{dashboardDark.value=localStorage.getItem('masal-dashboard-theme')==='dark';}catch{}
function apply(){
 const isDashboard=location.hash.startsWith('#/admin');
 // Keep the storefront palette and identity out of every dashboard route.
 document.documentElement.toggleAttribute('data-fatya-store',!isDashboard);
 const activeDark=isDashboard?dashboardDark.value:dark.value;
 document.documentElement.dataset.theme=activeDark?'dark':'light';
 document.querySelector('meta[name="theme-color"]')?.setAttribute('content',isDashboard?(activeDark?'#09131f':'#a21c2d'):(activeDark?'#14030a':'#a60233'));
 const base=import.meta.env.BASE_URL;
 document.querySelector('link[rel="icon"]')?.setAttribute('href',base+'favicon.png');
 document.querySelector('link[rel="apple-touch-icon"]')?.setAttribute('href',base+'icons/apple-touch-icon.png');
}
apply();
for(const [state,key] of [[dark,'masal-theme'],[dashboardDark,'masal-dashboard-theme']])watch(state,()=>{apply();try{localStorage.setItem(key,state.value?'dark':'light');}catch{}},{flush:'sync'});
window.addEventListener('hashchange',apply);
window.addEventListener('storage',event=>{if(event.key==='masal-theme')dark.value=event.newValue==='dark';else if(event.key==='masal-dashboard-theme')dashboardDark.value=event.newValue==='dark';else if(event.key===null){dark.value=false;dashboardDark.value=false;}else return;apply();});
export default {components:{ThemeScene},props:{scope:{type:String,default:'store'}},setup(props){return {t,language,dark:props.scope==='dashboard'?dashboardDark:dark};},template:`<button type="button" class="theme-toggle theme-scenic-toggle" @click="dark=!dark" :aria-pressed="dark" :aria-label="dark?t(&quot;تفعيل الوضع النهاري&quot;):t(&quot;تفعيل الوضع الليلي&quot;)" :title="dark?t(&quot;الوضع النهاري&quot;):t(&quot;الوضع الليلي&quot;)"><ThemeScene :dark="dark"/></button>`};
