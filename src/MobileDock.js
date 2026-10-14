import {t,language} from './i18n.js';
import {computed,useId} from 'vue/dist/vue.esm-bundler.js';
import './mobile-dock.css';
export default {
  props:{items:Array,icons:Object,page:String},emits:['navigate'],
  setup(props){
    const maskId='dock-'+useId();
    const selected=computed(()=>props.items.find(item=>item.id===props.page)||props.items[0]);
    const center=computed(()=>(((language.value==='en'?props.items.indexOf(selected.value):props.items.length-1-props.items.indexOf(selected.value))+.5)/props.items.length*100)+'%');
    return {t,language,maskId,selected,center};
  },
  template:`<nav class="bottom-nav masal-cutout-dock" :aria-label="t(&quot;التنقل الرئيسي&quot;)">
    <span class="dock-notch-blur" :style="{left:center}" aria-hidden="true"></span>
    <svg class="dock-surface" width="100%" height="100%" aria-hidden="true"><defs><mask :id="maskId" maskUnits="userSpaceOnUse" x="0" y="-80" width="100%" height="180"><rect width="100%" height="100%" fill="white"/><circle :style="{cx:center}" cy="0" fill="black" class="dock-notch"/></mask></defs><rect width="100%" height="100%" rx="30" class="dock-body" :mask="'url(#'+maskId+')'"/></svg>
    <span class="dock-bubble" :style="{left:center}" aria-hidden="true"><Transition name="dock-icon" mode="out-in"><component :is="icons[selected.icon]" :key="selected.id" :size="23"/></Transition></span>
    <button v-for="item in items" :key="item.id" :class="{active:page===item.id}" :aria-current="page===item.id?'page':undefined" @click="$emit('navigate',item.id)"><component :is="icons[item.icon]" :size="21" class="dock-idle-icon"/><span>{{t(item.label)}}</span></button>
  </nav>`
};
