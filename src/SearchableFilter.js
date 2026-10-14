import {ref,computed,nextTick,onMounted,onBeforeUnmount} from 'vue/dist/vue.esm-bundler.js';
import {Search,ChevronDown,Check} from 'lucide-vue-next';
import './searchable-filter.css';
export default {
 components:{Search,ChevronDown,Check},
 props:{modelValue:String,options:{type:Array,required:true},label:{type:String,required:true}},
 emits:['update:modelValue'],
 setup(props,{emit}){
  const root=ref(null),search=ref(''),input=ref(null);
  const filtered=computed(()=>props.options.filter(option=>option.includes(search.value.trim())));
  async function toggle(){if(root.value?.open){search.value='';await nextTick();input.value?.focus();}}
  function close(){if(root.value)root.value.open=false;}
  function choose(option){emit('update:modelValue',option);close();root.value?.querySelector('summary')?.focus();}
  function outside(event){if(root.value&&!event.composedPath().includes(root.value))close();}
  onMounted(()=>document.addEventListener('click',outside));
  onBeforeUnmount(()=>document.removeEventListener('click',outside));
  return {root,search,input,filtered,toggle,close,choose};
 },
 template:`<details ref="root" class="searchable-filter" @toggle="toggle" @keydown.esc.stop.prevent="close"><summary :aria-label="label"><span>{{modelValue}}</span><ChevronDown :size="16"/></summary><div class="searchable-filter-panel"><div class="searchable-filter-search"><Search :size="16"/><input ref="input" v-model="search" type="search" placeholder="ابحث في الخيارات..." :aria-label="'البحث في '+label"/></div><div class="searchable-filter-options" :aria-label="label"><button v-for="option in filtered" :key="option" type="button" :aria-pressed="modelValue===option" @click="choose(option)"><span>{{option}}</span><Check v-if="modelValue===option" :size="16"/></button><p v-if="!filtered.length">لا توجد نتائج</p></div></div></details>`
};
