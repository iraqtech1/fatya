import {t} from './i18n.js';
import {ref,onMounted,onBeforeUnmount,nextTick} from 'vue/dist/vue.esm-bundler.js';
import {Apple,Gamepad2,Gift} from 'lucide-vue-next';
import './splash.css';
export default {
  components:{Apple,Gamepad2,Gift},
  props:{enabled:{type:Boolean,default:true}},
  setup(props){
    const visible=ref(false),leaving=ref(false),reduced=ref(false),skip=ref(null);
    const logo=import.meta.env.BASE_URL+'brand/fatya-logo-green.png';
    const cards=[{theme:'apple',brand:'Apple',value:'$25',icon:Apple},{theme:'playstation',brand:'PlayStation',value:'$50',icon:Gamepad2},{theme:'google',brand:'Google Play',value:'$25',icon:Gift},{theme:'steam',brand:'STEAM',value:'$20',icon:Gamepad2}];
    let timer,exitTimer,content,previousOverflow,previousInert;
    function restore(){if(content)content.inert=previousInert;if(previousOverflow!==undefined)document.body.style.overflow=previousOverflow;}
    function finish(){
      if(leaving.value||!visible.value)return;
      clearTimeout(timer);leaving.value=true;
      exitTimer=setTimeout(async()=>{
        visible.value=false;restore();await nextTick();
        const heading=[...(content?.querySelectorAll('h1,h2')||[])].find(el=>el.getClientRects().length);
        if(heading){const hadTabindex=heading.hasAttribute('tabindex');if(!hadTabindex)heading.setAttribute('tabindex','-1');heading.focus({preventScroll:true});if(!hadTabindex)heading.removeAttribute('tabindex');}
      },reduced.value?0:260);
    }
    onMounted(async()=>{
      if(!props.enabled)return;
      reduced.value=matchMedia('(prefers-reduced-motion: reduce)').matches;
      content=document.getElementById('masal-content');previousInert=content?.inert??false;
      if(content)content.inert=true;
      previousOverflow=document.body.style.overflow;document.body.style.overflow='hidden';visible.value=true;
      timer=setTimeout(finish,reduced.value?600:7100);
      await nextTick();skip.value?.focus({preventScroll:true});
    });
    onBeforeUnmount(()=>{clearTimeout(timer);clearTimeout(exitTimer);restore();});
    return {t,visible,leaving,reduced,skip,logo,cards,finish};
  },
  template:`<div v-if="visible" class="masal-splash" :class="{'is-leaving':leaving,'is-reduced':reduced}" role="dialog" aria-modal="true" :aria-label="t('مرحباً بك في فاتيا')" @keydown.esc="finish">
    <div class="splash-glow" aria-hidden="true"></div>
    <div class="splash-scene" aria-hidden="true">
      <div class="splash-orbit-guide"></div>
      <div class="splash-launch-glow"></div>
      <div class="splash-logo">
        <svg class="splash-logo-color" viewBox="0 0 536 567" fill="none" aria-hidden="true"><defs><clipPath id="fatya-splash-lid"><path d="M116 119C116 91 128 71 151 63L325 14Q337 10 349 15L414 36Q424 40 424 49L424 65L348 87L337 89L337 91L285 106L212 128L116 155Z"/></clipPath><mask id="fatya-splash-body" maskUnits="userSpaceOnUse" x="0" y="0" width="536" height="567" mask-type="luminance"><rect width="536" height="567" fill="white"/><path d="M116 119C116 91 128 71 151 63L325 14Q337 10 349 15L414 36Q424 40 424 49L424 65L348 87L337 89L337 91L285 106L212 128L116 155Z" fill="black"/></mask><linearGradient id="fatya-pocket" x1="250" y1="40" x2="270" y2="140" gradientUnits="userSpaceOnUse"><stop stop-color="#020e0b"/><stop offset="1" stop-color="#0a4b36"/></linearGradient></defs><path class="splash-box-interior" d="M116 119C116 91 128 71 151 63L325 14Q337 10 349 15L414 36Q424 40 424 49L424 65L348 87L337 89L337 91L285 106L212 128L116 155Z" fill="url(#fatya-pocket)" stroke="#0a8b61" stroke-width="2"/><image :href="logo" width="536" height="567" mask="url(#fatya-splash-body)"/><g class="splash-box-lid"><image :href="logo" width="536" height="567" clip-path="url(#fatya-splash-lid)"/></g></svg>
        <svg class="splash-logo-lettering" viewBox="0 0 536 567" aria-hidden="true"><defs><filter id="fatya-original-wordmark" color-interpolation-filters="sRGB"><feColorMatrix type="matrix" values="-.69 0 0 0 .828 -.236 0 0 0 .853 -1.30 0 0 0 .826 0 0 0 1 0"/></filter></defs><image :href="logo" width="536" height="567" filter="url(#fatya-original-wordmark)"/></svg>
      </div>
      <div class="splash-card-orbit">
      <div v-for="(card,i) in cards" :key="card.theme" class="splash-flight" :style="{'--card-index':i}">
        <div class="splash-card" :class="'splash-gift-'+card.theme">
          <span class="splash-gift-caption">GIFT CARD</span>
          <svg v-if="card.theme==='google'" class="splash-gift-icon" viewBox="0 0 24 26" aria-hidden="true"><path d="M2 1 15 13 2 25Z" fill="#4285f4"/><path d="M2 1 18 10 15 13Z" fill="#34a853"/><path d="m15 13 3-3 5 3-5 3Z" fill="#fbbc04"/><path d="M2 25 15 13l3 3Z" fill="#ea4335"/></svg>
          <component v-else :is="card.icon" class="splash-gift-icon" :size="23" :stroke-width="1.7"/>
          <span class="splash-gift-brand">{{card.brand}}</span><span class="splash-gift-value">{{card.value}}</span>
        </div>
      </div>
      </div>
      <div class="splash-caption"><span>بطاقاتك، بمكان واحد</span><i></i></div>
    </div>
    <button ref="skip" class="splash-skip" @click="finish">{{t('تخطي')}}</button>
  </div>`
};
