import {ref,watch} from 'vue/dist/vue.esm-bundler.js';
import {api} from './api.js';
import {store,connection,refreshStore} from './store.js';
import {importCode} from './stock-import-formats.js';
export default {
 props:{reference:Array,mappings:Array},emits:['saved'],
 setup(props,{emit}){
  const rows=ref([]),busy=ref(false),error=ref(''),message=ref('');
  function reset(){const asia=store.products.find(p=>/asiacell|آسياسيل|اسياسيل/i.test(p.en+' '+p.name));const used=new Set();rows.value=(props.reference||[]).map(r=>{const m=props.mappings?.find(m=>r.codes.includes(m.code));r.codes.forEach(c=>used.add(c));const p=m?store.products.find(p=>String(p.id)===m.productId):null;return {id:crypto.randomUUID(),label:r.label,codes:r.codes.join(', '),productId:p?.id??'',denom:m?p.values.findIndex(v=>+v===+m.value):p?.values.findIndex(v=>v===r.face)??-1};});for(const m of props.mappings||[])if(!used.has(m.code)){const p=store.products.find(p=>String(p.id)===m.productId);rows.value.push({id:crypto.randomUUID(),label:'رمز مخصص',codes:m.code,productId:p?.id??'',denom:p?.values.findIndex(v=>+v===+m.value)??-1});}}
  watch(()=>[props.reference,props.mappings],reset,{immediate:true});
  function add(){rows.value.push({id:crypto.randomUUID(),label:'رمز مخصص',codes:'',productId:'',denom:-1});}
  async function save(){if(busy.value)return;busy.value=true;error.value='';message.value='';try{const mappings=rows.value.filter(r=>r.productId&&r.denom>=0).flatMap(r=>r.codes.split(/[,،;\s]+/).filter(Boolean).map(code=>({code:importCode(code),productId:String(r.productId),denom:r.denom})));await api('/admin/procurement/stock-orders/codes',{revision:connection.revision,mappings},'PUT');await refreshStore();emit('saved');message.value='تم حفظ ربط الرموز؛ الفئات غير المربوطة تبقى للاختيار اليدوي';}catch(e){error.value=e.message;}finally{busy.value=false;}}
  return {rows,busy,error,message,store,add,save};
 },
 template:`<details class="admin-panel"><summary>قاموس فئات آسياسيل ورموز ملفات المصدر — 28 فئة</summary><article v-for="r in rows" :key="r.id" class="proc-code-row"><h3>{{r.label}}</h3><div class="proc-grid"><label>الرموز<input v-model="r.codes" dir="ltr" :disabled="busy" maxlength="400"/></label><label>البطاقة<select v-model="r.productId" @change="r.denom=-1" :disabled="busy"><option value="">غير مربوط</option><option v-for="p in store.products" :key="p.id" :value="p.id">{{p.name}}</option></select></label><label>الفئة<select v-model="r.denom" :disabled="busy"><option :value="-1">غير مربوط</option><option v-for="(v,i) in store.products.find(p=>String(p.id)===String(r.productId))?.values||[]" :key="i" :value="i">{{v}}</option></select></label></div></article><div class="proc-actions"><button class="admin-secondary" @click="add" :disabled="busy">إضافة رمز مخصص</button><button class="admin-primary" @click="save" :disabled="busy">حفظ ربط الرموز</button></div><p v-if="error" role="alert">{{error}}</p><p v-if="message" role="status">{{message}}</p></details>`
};
