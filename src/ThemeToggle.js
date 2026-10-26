import {routePath,inRoute} from './routing.js';
import {isWebStore} from './store-surface.js';
import {t,language} from './i18n.js';
import {ref,watch,computed} from 'vue/dist/vue.esm-bundler.js';
import ThemeScene from './ThemeScene.js';
import {Sun,Moon} from 'lucide-vue-next';
export const dark=ref(false);
export const dashboardDark=ref(false);
export const webDark=ref(false);
try{dark.value=localStorage.getItem('masal-theme')==='dark';}catch{}
try{dashboardDark.value=localStorage.getItem('masal-dashboard-theme')==='dark';}catch{}
try{webDark.value=localStorage.getItem('masal-web-theme')==='dark';}catch{}
export const storeDark=computed({get:()=>isWebStore()?webDark.value:dark.value,set:value=>{if(isWebStore())webDark.value=value;else dark.value=value;}});
function apply(){
 const isDashboard=inRoute(routePath(),'/admin');
 // Scope storefront treatments while sharing the green identity across both apps.
 document.documentElement.toggleAttribute('data-fatya-store',!isDashboard);
 const web=isWebStore();
 document.documentElement.toggleAttribute('data-fatya-web',web);
 const activeDark=web?webDark.value:isDashboard?dashboardDark.value:dark.value;
 document.documentElement.dataset.theme=activeDark?'dark':'light';
 document.querySelector('meta[name="theme-color"]')?.setAttribute('content',isDashboard?(activeDark?'#061a14':'#034037'):(activeDark?'#061a14':'#0a8b61'));
 const base=import.meta.env.BASE_URL;
 document.querySelector('link[rel="icon"]')?.setAttribute('href',base+'favicon-green.png');
 document.querySelector('link[rel="apple-touch-icon"]')?.setAttribute('href',base+'icons/apple-touch-green.png');
 document.querySelector('meta[name="viewport"]')?.setAttribute('content',web?'width=device-width, initial-scale=1.0, viewport-fit=cover':'width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover');
}
apply();
for(const [state,key] of [[dark,'masal-theme'],[dashboardDark,'masal-dashboard-theme'],[webDark,'masal-web-theme']])watch(state,()=>{apply();try{localStorage.setItem(key,state.value?'dark':'light');}catch{}},{flush:'sync'});
window.addEventListener('hashchange',apply);
window.addEventListener('storage',event=>{if(event.key==='masal-theme')dark.value=event.newValue==='dark';else if(event.key==='masal-dashboard-theme')dashboardDark.value=event.newValue==='dark';else if(event.key==='masal-web-theme')webDark.value=event.newValue==='dark';else if(event.key===null){dark.value=false;dashboardDark.value=false;webDark.value=false;}else return;apply();});
export default {components:{ThemeScene,Sun,Moon},props:{scope:{type:String,default:'store'}},setup(props){return {t,language,dark:props.scope==='dashboard'?dashboardDark:props.scope==='web'?webDark:storeDark};},template:`<button type="button" class="theme-toggle" :class="scope==='web'?'theme-web-toggle':'theme-scenic-toggle'" @click="dark=!dark" :aria-pressed="dark" :aria-label="dark?t(&quot;تفعيل الوضع النهاري&quot;):t(&quot;تفعيل الوضع الليلي&quot;)" :title="dark?t(&quot;الوضع النهاري&quot;):t(&quot;الوضع الليلي&quot;)"><template v-if="scope==='web'"><Sun v-if="dark" :size="20" aria-hidden="true"/><Moon v-else :size="20" aria-hidden="true"/></template><ThemeScene v-else :dark="dark"/></button>`};

window.addEventListener('fatya:routechange',apply);
globalThis.matchMedia?.('(display-mode: standalone)').addEventListener('change',apply);
