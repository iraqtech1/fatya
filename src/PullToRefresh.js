import {t,language} from './i18n.js';
import {ref,computed,onMounted,onBeforeUnmount} from 'vue/dist/vue.esm-bundler.js';
import {RefreshCw} from 'lucide-vue-next';
import {pullDistance,refreshThreshold} from './pull-gesture.js';
import './pull-refresh.css';

export default {
  components:{RefreshCw},
  setup(){
    const distance=ref(0),refreshing=ref(false);
    const ready=computed(()=>distance.value>=refreshThreshold);
    let start=null,locked=false,frame,oldOverscroll;
    function reset(){start=null;locked=false;distance.value=0;}
    function scrollTop(){return document.querySelector('.app-shell>main')?.scrollTop||window.scrollY;}
    function down(e){
      reset();
      if(refreshing.value||innerWidth>700||e.touches.length!==1||scrollTop()>1||document.querySelector('dialog[open]'))return;
      const target=e.target;
      if(target.closest('input,textarea,select,[contenteditable="true"],.bottom-nav,.deck-stage'))return;
      for(let el=target;el&&el!==document.body;el=el.parentElement){
        if(el.matches('.app-shell>main'))break;
        if(el.scrollHeight>el.clientHeight&&/auto|scroll/.test(getComputedStyle(el).overflowY))return;
      }
      start={x:e.touches[0].clientX,y:e.touches[0].clientY};
    }
    function move(e){
      if(!start)return;
      if(e.touches.length!==1||scrollTop()>1){reset();return;}
      const dx=e.touches[0].clientX-start.x,dy=e.touches[0].clientY-start.y;
      if(!locked&&Math.max(Math.abs(dx),Math.abs(dy))<10)return;
      if(!locked&&(dy<=0||Math.abs(dx)>=dy)){reset();return;}
      locked=true;
      if(e.cancelable)e.preventDefault();
      distance.value=pullDistance(dx,dy);
    }
    function up(){
      const shouldRefresh=ready.value;
      reset();
      if(!shouldRefresh||refreshing.value)return;
      refreshing.value=true;
      distance.value=refreshThreshold;
      // Paint the feedback before a real page reload, preserving the current URL.
      frame=requestAnimationFrame(()=>{frame=requestAnimationFrame(()=>window.location.reload());});
    }
    onMounted(()=>{
      oldOverscroll=document.body.style.overscrollBehaviorY;
      document.body.style.overscrollBehaviorY='contain';
      document.addEventListener('touchstart',down,{passive:true});
      document.addEventListener('touchmove',move,{passive:false});
      document.addEventListener('touchend',up);
      document.addEventListener('touchcancel',reset);
    });
    onBeforeUnmount(()=>{
      cancelAnimationFrame(frame);
      document.body.style.overscrollBehaviorY=oldOverscroll;
      document.removeEventListener('touchstart',down);
      document.removeEventListener('touchmove',move);
      document.removeEventListener('touchend',up);
      document.removeEventListener('touchcancel',reset);
    });
    return {t,language,distance,ready,refreshing};
  },
  template:`<div class="pull-refresh" :class="{visible:distance>0,refreshing}" :style="{'--pull-distance':distance+'px'}" role="status" aria-live="polite"><RefreshCw :size="20" :style="{transform:'rotate('+distance*3+'deg)'}"/><span>{{refreshing?t("جاري التحديث…"):ready?t("اترك للتحديث"):t("اسحب للأسفل للتحديث")}}</span></div>`
};
