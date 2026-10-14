import {ref,computed,watch,nextTick} from 'vue/dist/vue.esm-bundler.js';
import {t} from './i18n.js';
import {pageCount,clampPage,pageItems,pageNumbers} from './pagination.js';
import './pagination.css';
export default {
  props:{items:{type:Array,required:true},size:{type:Number,default:8},resetKey:{default:''},admin:{type:Boolean,default:false},scroll:{type:Boolean,default:true}},
  setup(props){
    const page=ref(1),root=ref(null);
    const total=computed(()=>pageCount(props.items.length,props.size));
    const current=computed(()=>clampPage(page.value,props.items.length,props.size));
    const visible=computed(()=>pageItems(props.items,current.value,props.size));
    const numbers=computed(()=>pageNumbers(current.value,total.value));
    watch(()=>props.resetKey,()=>{page.value=1;});
    watch(total,()=>{page.value=current.value;});
    const label=text=>props.admin?text:t(text);
    async function change(value){page.value=clampPage(value,props.items.length,props.size);await nextTick();if(props.scroll)root.value?.scrollIntoView({block:'start',behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'instant':'smooth'});}
    return {page,root,total,current,visible,numbers,label,change};
  },
  template:`<div class="paged-list" ref="root"><slot :items="visible" :offset="(current-1)*size"/>
    <nav v-if="total>1" class="pagination" :aria-label="label('التنقل بين الصفحات')">
      <div class="pagination-buttons"><button type="button" :disabled="current===1" @click="change(current-1)">{{label('السابق')}}</button>
      <template v-if="numbers[0]>1"><button type="button" @click="change(1)" :aria-label="label('الصفحة')+' 1'">1</button><span v-if="numbers[0]>2" aria-hidden="true">…</span></template>
      <button type="button" v-for="n in numbers" :key="n" :class="{active:n===current}" :aria-current="n===current?'page':undefined" :aria-label="label('الصفحة')+' '+n" @click="change(n)">{{n}}</button>
      <template v-if="numbers[numbers.length-1]<total"><span v-if="numbers[numbers.length-1]<total-1" aria-hidden="true">…</span><button type="button" @click="change(total)" :aria-label="label('الصفحة')+' '+total">{{total}}</button></template>
      <button type="button" :disabled="current===total" @click="change(current+1)">{{label('التالي')}}</button></div>
      <span class="pagination-summary" role="status">{{label('الصفحة')}} {{current}} {{label('من')}} {{total}}</span>
    </nav></div>`
};
