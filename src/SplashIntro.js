import {t} from './i18n.js';
import {ref,onMounted,onBeforeUnmount,nextTick} from 'vue/dist/vue.esm-bundler.js';
import {CreditCard,Smartphone,Gamepad2,Monitor,Cpu} from 'lucide-vue-next';
import './splash.css';
export default {
  components:{CreditCard,Smartphone,Gamepad2,Monitor,Cpu},
  props:{enabled:{type:Boolean,default:true}},
  setup(props){
    const visible=ref(false),leaving=ref(false),reduced=ref(false),skip=ref(null);
    const logo=import.meta.env.BASE_URL+'brand/fatya-logo-smooth.svg';
    const symbols=[CreditCard,Smartphone,Gamepad2,Monitor,Cpu];
    const sparks=Array.from({length:12},(_,i)=>i);
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
      timer=setTimeout(finish,reduced.value?600:3740);
      await nextTick();skip.value?.focus({preventScroll:true});
    });
    onBeforeUnmount(()=>{clearTimeout(timer);clearTimeout(exitTimer);restore();});
    return {t,visible,leaving,reduced,skip,logo,symbols,sparks,finish};
  },
  template:`<div v-if="visible" class="masal-splash" :class="{'is-leaving':leaving,'is-reduced':reduced}" role="dialog" aria-modal="true" :aria-label="t('مرحباً بك في فاتيا')" @keydown.esc="finish">
    <div class="splash-glow" aria-hidden="true"></div>
    <div class="splash-scene" aria-hidden="true">
      <div class="splash-seed splash-seed-top"></div><div class="splash-seed splash-seed-bottom"></div>
      <div class="splash-reveal-pulse"></div>
      <div class="splash-sparks"><span v-for="i in sparks" :key="i" :style="{'--spark-index':i}" :class="'spark-shape-'+(i%3)"></span></div>
      <div class="splash-logo"><img class="splash-logo-image" :src="logo" alt="" width="536" height="567" fetchpriority="high" draggable="false"/></div>
      <div class="splash-symbol-orbit"><span v-for="(symbol,i) in symbols" :key="i" class="splash-symbol-flight" :style="{'--symbol-index':i}"><i><component :is="symbol" :size="23" :stroke-width="1.7"/></i></span></div>
      <div class="splash-caption"><span>بطاقاتك، بمكان واحد</span><i></i></div>
    </div>
    <button ref="skip" class="splash-skip" @click="finish">{{t('تخطي')}}</button>
  </div>`
};
