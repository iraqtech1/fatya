import {t,language} from './i18n.js';
import {ref,onMounted,onBeforeUnmount,nextTick} from 'vue/dist/vue.esm-bundler.js';
import {CreditCard,Gamepad2,Headphones,Smartphone,Monitor,Cpu} from 'lucide-vue-next';
import StoreBrandMark from './StoreBrandMark.js';
import './splash.css';
export default {
  components:{StoreBrandMark,CreditCard,Gamepad2,Headphones,Smartphone,Monitor,Cpu},
  props:{enabled:{type:Boolean,default:true}},
  setup(props){
    const visible=ref(false),leaving=ref(false),reduced=ref(false);
    const symbols=[CreditCard,Smartphone,Gamepad2,Headphones,Monitor,Cpu];
    let timer,exitTimer,content,previousOverflow;
    function restore(){if(content)content.inert=false;if(previousOverflow!==undefined)document.body.style.overflow=previousOverflow;}
    function finish(){if(leaving.value||!visible.value)return;clearTimeout(timer);leaving.value=true;exitTimer=setTimeout(async()=>{visible.value=false;restore();await nextTick();document.querySelector('.auth-panel h1')?.focus({preventScroll:true});},reduced.value?0:260);}
    onMounted(()=>{
      if(!props.enabled)return;
      reduced.value=matchMedia('(prefers-reduced-motion: reduce)').matches;
      content=document.getElementById('masal-content');if(content)content.inert=true;
      previousOverflow=document.body.style.overflow;document.body.style.overflow='hidden';visible.value=true;
      timer=setTimeout(finish,reduced.value?3000:2740);
    });
    onBeforeUnmount(()=>{clearTimeout(timer);clearTimeout(exitTimer);restore();});
    return {t,language,visible,leaving,reduced,symbols,finish};
  },
  template:`<div v-if="visible" class="masal-splash" :class="{'is-leaving':leaving,'is-reduced':reduced}" role="dialog" aria-modal="true" :aria-label="t(&quot;مرحباً بك في فاتيا&quot;)" @keydown.esc="finish"><div class="splash-glow" aria-hidden="true"></div><div class="splash-scene"><div class="splash-symbols" aria-hidden="true"><span v-for="(symbol,i) in symbols" :key="i" :style="{'--i':i}"><component :is="symbol" :size="24"/></span></div><div class="splash-logo"><StoreBrandMark full/></div><div class="splash-wordmark"><strong>{{t("فاتيا")}}</strong><span lang="en" dir="ltr">FATYA</span></div><div class="splash-line" aria-hidden="true"></div></div><button class="splash-skip" @click="finish">{{t("تخطي")}}</button></div>`
};
