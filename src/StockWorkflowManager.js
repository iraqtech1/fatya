import {ref,onMounted} from 'vue/dist/vue.esm-bundler.js';
import {api,connected} from './api.js';
import {refreshStore} from './store.js';
import PagedList from './PagedList.js';
export default {
 components:{PagedList},emits:['correct','reviewed'],
 setup(props,{emit}){
  const policy=ref({required:false,version:0}),items=ref([]),busy=ref(false),error=ref(''),message=ref(''),reasons=ref({});const keys=new Map();
  async function load(){const r=await api('/admin/procurement/stock-orders');items.value=r.submissions;policy.value=r.policy;}
  async function run(task){if(busy.value)return;busy.value=true;error.value='';message.value='';try{if(!connected)throw Error('يحتاج اتصالاً بالخادم');await task();}catch(e){error.value=e.message;}finally{busy.value=false;}}
  function save(){return run(async()=>{policy.value=await api('/admin/procurement/stock-orders/policy',{version:policy.value.version,required:policy.value.required},'PUT');await refreshStore();message.value='تم حفظ سياسة الاعتماد';});}
  function review(r,decision){return run(async()=>{const b={id:r.id,version:r.version,decision,reason:reasons.value[r.id]||''},hash=JSON.stringify(b);if(!keys.has(hash))keys.set(hash,crypto.randomUUID());await api('/admin/procurement/stock-orders/review',{...b,requestId:keys.get(hash)});await refreshStore();await load();message.value='تم حفظ قرار المراجعة';emit('reviewed');});}
  const label=s=>({pending:'بانتظار الموافقة',approved:'معتمدة',returned:'معادة للتصحيح',rejected:'مرفوضة'}[s]||s);
  onMounted(()=>run(load));return {policy,items,busy,error,message,reasons,run,load,save,review,label,correct:r=>emit('correct',r)};
 },
 template:`<details class="admin-panel"><summary>سياسة الاعتماد وسجل الإرسال والموافقات</summary><label class="admin-checkbox"><input type="checkbox" v-model="policy.required" :disabled="busy"/> إرسال طلبيات المخزون للمراجعة قبل إضافتها</label><div class="proc-actions"><button class="admin-primary" @click="save" :disabled="busy">حفظ السياسة</button><button class="admin-secondary" @click="run(load)" :disabled="busy">تحديث السجل</button></div><p v-if="error" role="alert">{{error}}</p><p v-if="message" role="status">{{message}}</p><p v-if="!items.length">لا توجد طلبيات مرسلة للمراجعة.</p><PagedList history-key="stockworkflowmanager-1" :items="items" :size="10" admin v-slot="{items:rows}"><article v-for="r in rows" :key="r.id" class="proc-code-row"><h3>{{r.supplier}} / {{r.source}}</h3><p>{{label(r.status)}} • {{r.quantity}} بطاقة • {{r.costTotal}} IQD</p><p v-if="r.reason">{{r.reason}}</p><template v-if="r.status==='pending'"><label>سبب الإعادة أو الرفض<input v-model="reasons[r.id]" maxlength="1000" :disabled="busy"/></label><div class="proc-actions"><button class="admin-primary" @click="review(r,'approve')" :disabled="busy">اعتماد</button><button class="admin-secondary" @click="review(r,'return')" :disabled="busy">إعادة للتصحيح</button><button class="admin-secondary" @click="review(r,'reject')" :disabled="busy">رفض</button></div></template><button v-if="r.status==='returned'" class="admin-secondary" @click="correct(r)" :disabled="busy">تصحيح وإعادة إرسال</button></article></PagedList></details>`
};
