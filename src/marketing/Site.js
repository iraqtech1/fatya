import {shared} from './components.js';
import './site.css';
import './pages.css';
import './motion.css';
import './contrast.css';
import '../brand.css';
import {useRoute} from 'vue-router';
import {useReveal} from './reveal.js';
export default {components:shared,setup(){const route=useRoute();useReveal(route);return {route}},template:`<div class="ms-site" dir="rtl"><RouterLink class="ms-skip" :to="{path:route.path,hash:'#ms-content'}">انتقل إلى المحتوى</RouterLink><Navbar/><main class="ms-main" id="ms-content" tabindex="-1"><RouterView/></main><Footer/></div>`};
