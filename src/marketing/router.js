import {createRouter,createWebHashHistory,createWebHistory} from 'vue-router';
import {hashRouting,legacyDestination,setRouteNavigator} from '../routing.js';
if(!hashRouting){const destination=legacyDestination(location);if(destination)history.replaceState(history.state,'',destination);}
import Home from './Home.js';
import About from './About.js';
import MiniApps from './MiniApps.js';
import Business from './Business.js';
import Partnerships from './Partnerships.js';
import Insights from './Insights.js';
import {updateMeta} from './seo.js';
export const marketingRoutes=[
 {path:'/ar',component:Home},
 {path:'/ar/about',component:About},
 {path:'/ar/mini-apps',component:MiniApps},
 {path:'/ar/business',component:Business},
 {path:'/ar/insights',component:Insights},
 {path:'/ar/business/partnerships',component:Partnerships},
];
export const router=createRouter({history:hashRouting?createWebHashHistory(import.meta.env.BASE_URL):createWebHistory(import.meta.env.BASE_URL),routes:[...marketingRoutes,{path:'/:pathMatch(.*)*',component:{template:'<span/>'}}],scrollBehavior(to,from,saved){if(saved)return saved;if(to.hash)return {el:to.hash,top:95,behavior:window.matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth'};return {top:0}}});
setRouteNavigator(path=>router.push(path));
if(!hashRouting)window.addEventListener('hashchange',()=>{const destination=legacyDestination(location);if(destination)router.replace(destination);});
router.afterEach(to=>{updateMeta(to.path);window.dispatchEvent(new Event('fatya:routechange'));});
