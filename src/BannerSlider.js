import {t,language} from './i18n.js';
import {ref,computed,watch,onMounted,onBeforeUnmount} from 'vue/dist/vue.esm-bundler.js';
import {slides as topSlides,middleSlides} from './slides.js';
import './slider.css';
export default {
  props:{showPlayback:{type:Boolean,default:true},placement:{type:String,default:'top'},interval:{type:Number,default:6500},label:{type:String,default:'عروض فاتيا'}},
  setup(props){
    const slides=props.placement==='middle'?middleSlides:topSlides;
    const index=ref(0),paused=ref(false),hover=ref(false),focused=ref(false),dragging=ref(false),reduced=ref(false);
    let timer,startX=0,startY=0,pointer=null,media;
    function move(step){index.value=(index.value+step+slides.length)%slides.length;restart();}
    function restart(){clearInterval(timer);if(slides.length>1&&!paused.value&&!hover.value&&!focused.value&&!dragging.value&&!reduced.value&&!document.hidden)timer=setInterval(()=>index.value=(index.value+1)%slides.length,props.interval);}
    function down(e){if(!e.isPrimary||e.button!==0)return;pointer=e.pointerId;startX=e.clientX;startY=e.clientY;dragging.value=true;}
    function up(e){if(pointer!==e.pointerId)return;const dx=e.clientX-startX,dy=e.clientY-startY;pointer=null;dragging.value=false;if(Math.abs(dx)>45&&Math.abs(dx)>Math.abs(dy))move(dx<0?1:-1);}
    function cancel(){pointer=null;dragging.value=false;}
    function preference(){reduced.value=media.matches;}
    watch(()=>slides.map(s=>s.id).join(','),()=>{index.value=Math.min(index.value,Math.max(0,slides.length-1));restart();});
    watch([paused,hover,focused,dragging,reduced],restart);
    onMounted(()=>{media=matchMedia('(prefers-reduced-motion: reduce)');preference();media.addEventListener('change',preference);document.addEventListener('visibilitychange',restart);window.addEventListener('pointerup',up);window.addEventListener('pointercancel',cancel);restart();});
    onBeforeUnmount(()=>{clearInterval(timer);media.removeEventListener('change',preference);document.removeEventListener('visibilitychange',restart);window.removeEventListener('pointerup',up);window.removeEventListener('pointercancel',cancel);});
    return {t,language,slides,index,paused,hover,focused,move,down};
  },
  template:`<section v-if="slides.length" class="banner-slider" :aria-label="t(label)" :aria-roledescription="t('عارض صور')" @mouseenter="hover=true" @mouseleave="hover=false" @focusin="focused=true" @focusout="focused=$event.currentTarget.contains($event.relatedTarget)" @keydown.left.prevent="move(-1)" @keydown.right.prevent="move(1)">
    <div class="banner-window" @pointerdown="down"><Transition name="banner-fade"><img :key="slides[index].id" :src="slides[index].src" :alt="t(slides[index].name)" draggable="false" decoding="async"/></Transition></div>
    <div v-if="slides.length>1" class="banner-controls" dir="ltr"><div class="banner-dots"><button v-for="(slide,i) in slides" :key="slide.id" :class="{active:index===i}" :aria-label="t(&quot;عرض الصورة &quot;)+(i+1)" :aria-current="index===i?'true':undefined" @click="index=i"></button></div></div>
  </section>`
};
