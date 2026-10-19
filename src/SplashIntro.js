import {t} from './i18n.js';
import {ref,onMounted,onBeforeUnmount,nextTick} from 'vue/dist/vue.esm-bundler.js';
import {CreditCard,Gamepad2,Smartphone,ShoppingBag} from 'lucide-vue-next';
import './splash.css';
export default {
  components:{CreditCard,Gamepad2,Smartphone,ShoppingBag},
  props:{enabled:{type:Boolean,default:true}},
  setup(props){
    const visible=ref(false),leaving=ref(false),reduced=ref(false),skip=ref(null);
    const logo=import.meta.env.BASE_URL+'brand/fatya-logo-green.png';
    const cards=[{icon:Gamepad2,label:'GAMES'},{icon:Smartphone,label:'CREDIT'},{icon:ShoppingBag,label:'STORE'},{icon:CreditCard,label:'CARDS'}];
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
      timer=setTimeout(finish,reduced.value?600:4200);
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
        <img class="splash-logo-color" :src="logo" alt="" width="536" height="567" fetchpriority="high" draggable="false"/>
        <img class="splash-logo-lettering" :src="logo" alt="" width="536" height="567" draggable="false"/>
      </div>
      <div v-for="(card,i) in cards" :key="card.label" class="splash-flight" :style="{'--card-index':i}">
        <div class="splash-card" :class="'splash-card-'+i">
          <span class="splash-card-brand">FATYA</span><component :is="card.icon" :size="21" :stroke-width="1.6"/>
          <span class="splash-card-chip"></span><span class="splash-card-label">{{card.label}}</span>
        </div>
      </div>
      <div class="splash-caption"><span>بطاقاتك، بمكان واحد</span><i></i></div>
    </div>
    <button ref="skip" class="splash-skip" @click="finish">{{t('تخطي')}}</button>
  </div>`
};
