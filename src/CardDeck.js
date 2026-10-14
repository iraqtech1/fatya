import {t,language} from './i18n.js';
import {ref,computed,onMounted,onUnmounted} from 'vue/dist/vue.esm-bundler.js';
import {ChevronUp,ChevronDown} from 'lucide-vue-next';
import './card-deck.css';
import {deckSwipeStep} from './deck-gesture.js';

export default {
  components:{ChevronUp,ChevronDown},
  props:{products:Array},
  emits:['select'],
  setup(props,{emit}){
    const dragY=ref(0),dragging=ref(false);
    const active=ref(0),hovered=ref(false),focused=ref(false),reduced=ref(false);
    const current=computed(()=>props.products[active.value]);
    let timer,media,start=null,moved=false;
    const move=step=>active.value=(active.value+step+props.products.length)%props.products.length;
    const offset=i=>{let n=(i-active.value+props.products.length)%props.products.length;return n>props.products.length/2?n-props.products.length:n};
    const style=i=>{const n=offset(i);return {'--slot':n,'--drag-y':dragY.value+'px','--tilt':`${n===0?-3:n%2===0?-12:10}deg`,zIndex:10-Math.abs(n),opacity:Math.abs(n)>2?0:1,visibility:Math.abs(n)>2?'hidden':'visible'}};
    function begin(x,y,id,kind){start={x,y,id,kind};moved=false;dragY.value=0;hovered.value=true}
    function track(x,y){if(!start)return;const dx=x-start.x,dy=y-start.y;if(Math.max(Math.abs(dx),Math.abs(dy))>12){moved=true;dragging.value=true;dragY.value=Math.max(-100,Math.min(100,dy))}}
    function finish(x,y){if(!start)return;const step=deckSwipeStep(x-start.x,y-start.y);if(step){moved=true;move(step)}reset()}
    function reset(){start=null;dragging.value=false;dragY.value=0;hovered.value=false}
    function down(e){if(e.pointerType==='touch'||e.button!==0)return;begin(e.clientX,e.clientY,e.pointerId,'pointer')}
    function drag(e){if(!start||start.kind!=='pointer'||e.pointerId!==start.id)return;track(e.clientX,e.clientY);if(moved&&!e.currentTarget.hasPointerCapture(e.pointerId))e.currentTarget.setPointerCapture(e.pointerId)}
    function up(e){if(!start||start.kind!=='pointer'||e.pointerId!==start.id)return;const id=start.id;finish(e.clientX,e.clientY);if(e.currentTarget.hasPointerCapture(id))e.currentTarget.releasePointerCapture(id)}
    function cancel(e){if(start?.kind==='pointer'&&e.pointerType!=='touch'&&e.target===e.currentTarget)reset()}
    // Native touch events avoid implicit pointer-capture transfers on mobile Safari.
    function touchDown(e){if(e.touches.length!==1){reset();return}const t=e.touches[0];begin(t.clientX,t.clientY,t.identifier,'touch')}
    function touchDrag(e){if(!start||start.kind!=='touch')return;if(e.touches.length!==1){reset();return}const t=Array.from(e.touches).find(t=>t.identifier===start.id);if(t){if(e.cancelable)e.preventDefault();track(t.clientX,t.clientY)}}
    function touchUp(e){if(!start||start.kind!=='touch')return;const t=Array.from(e.changedTouches).find(t=>t.identifier===start.id);if(t)finish(t.clientX,t.clientY)}
    function select(p,i){if(moved){moved=false;return}if(i===active.value)emit('select',p);else active.value=i}
    function key(e){if(e.key==='ArrowDown'||e.key==='ArrowRight'){e.preventDefault();move(1)}if(e.key==='ArrowUp'||e.key==='ArrowLeft'){e.preventDefault();move(-1)}}
    const motion=e=>reduced.value=e.matches;
    onMounted(()=>{media=matchMedia('(prefers-reduced-motion: reduce)');reduced.value=media.matches;media.addEventListener('change',motion);timer=setInterval(()=>{if(!hovered.value&&!focused.value&&!reduced.value&&!document.hidden)move(1)},3200)});
    onUnmounted(()=>{clearInterval(timer);media?.removeEventListener('change',motion)});
    return {t,language,dragging,active,current,hovered,focused,reduced,move,offset,style,down,drag,up,cancel,touchDown,touchDrag,touchUp,reset,select,key};
  },
  template:`<section class="card-deck" :aria-label="t(&quot;الكارتات المميزة&quot;)" aria-roledescription="carousel" @mouseenter="hovered=true" @mouseleave="hovered=false" @focusin="focused=true" @focusout="focused=false" @keydown="key">
    <div class="deck-stage" :class="{'is-dragging':dragging}" @touchstart="touchDown" @touchmove="touchDrag" @touchend="touchUp" @touchcancel="reset" @pointerdown="down" @pointermove="drag" @pointerup="up" @pointercancel="cancel" @lostpointercapture="cancel">
      <button v-for="(p,i) in products" :key="p.id" class="deck-card" :class="{'deck-current':i===active,'deck-white':i%2===1,'deck-card-with-art':!!p.image}" :style="style(i)" :tabindex="Math.abs(offset(i))<=2?0:-1" :aria-hidden="Math.abs(offset(i))>2" :aria-label="i===active?t(&quot;اختيار بطاقة &quot;)+t(p.name):t(&quot;عرض بطاقة &quot;)+t(p.name)" @click="select(p,i)">
        <img v-if="p.image" class="deck-card-art" :src="p.image" :alt="t(p.name)" loading="lazy"/>
        <span class="deck-card-top"><span>FATYA / {{String(i+1).padStart(2,'0')}}</span><span>{{t(p.category)}}</span></span>
        <strong dir="ltr">{{p.en}}</strong><span class="deck-card-bottom"><span>{{t(p.name)}}</span><span>{{t(p.tag)}}</span></span>
      </button>
    </div>
    <div class="deck-controls"><button @click="move(-1)" :aria-label="t(&quot;الكارت السابق&quot;)"><ChevronUp :size="18"/></button><span>{{t(current.name)}} <small dir="ltr">{{active+1}} / {{products.length}}</small></span><button @click="move(1)" :aria-label="t(&quot;الكارت التالي&quot;)"><ChevronDown :size="18"/></button></div>
    <p class="deck-hint">{{t("اسحب للأعلى أو الأسفل · اضغط الكارت للشراء")}}</p>
  </section>`
};
