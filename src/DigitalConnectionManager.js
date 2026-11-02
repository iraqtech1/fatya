import {ref,onMounted} from 'vue/dist/vue.esm-bundler.js';
import {api} from './api.js';
import {refreshStore} from './store.js';
export default {
 emits:['saved'],
 setup(props,{emit}){
  const rows=ref([]),busy=ref(false),error=ref(''),message=ref('');
  async function load(){const r=await api('/admin/procurement/digital');rows.value=r.connections.map(c=>({...c,name:c.id==='topup'?'TOPUP':c.name,credential:'',reason:''}));}
  async function run(task){if(busy.value)return;busy.value=true;error.value='';message.value='';try{await task();}catch(e){error.value=e.message;}finally{busy.value=false;}}
  function save(c){return run(async()=>{await api('/admin/procurement/digital/connections',{provider:c.id,version:c.version,active:c.active,credential:c.credential,reason:c.reason},'PUT');c.credential='';await refreshStore();await load();emit('saved');message.value='حُفظ الربط مشفراً. اجلب الكتالوج للتأكد من صلاحية الاتصال؛ الحفظ وحده لا يثبت نجاحه.';});}
  onMounted(()=>run(load));return {rows,busy,error,message,save};
 },
 template:`<section class="admin-panel"><h2>إعداد ربط الشركات ومفاتيح API</h2><p>المفتاح الخام بدون Bearer. يخزن مشفراً في الخادم ولا يعاد في بيانات الصفحة. الحقل الفارغ عند التعديل يحافظ على المفتاح الحالي. لا تغيّر المفتاح أثناء وجود عمليات معلقة أو للمراجعة.</p><article v-for="c in rows" :key="c.id" class="proc-code-row"><h3>{{c.name}}</h3><p>{{c.hasCredential?'يوجد مفتاح محفوظ':'لم يُحفظ مفتاح'}} — الإصدار {{c.version}}</p><label>مفتاح {{c.name}}<input v-model="c.credential" type="password" autocomplete="off" dir="ltr" maxlength="4096" :disabled="busy" placeholder="اتركه فارغاً للحفاظ على الحالي"/></label><label>سبب تبديل المفتاح<input v-model="c.reason" maxlength="500" :disabled="busy"/></label><label class="admin-checkbox"><input v-model="c.active" type="checkbox" :disabled="busy"/> تفعيل اتصال الشركة</label><button class="admin-primary" @click="save(c)" :disabled="busy">حفظ إعداد {{c.name}}</button><p v-if="c.balance!==null&&c.balance!==undefined">آخر رصيد {{c.balance}} IQD — {{c.balanceUpdatedAt}}</p></article><p>الرابعة: اجلب المحافظات ثم الكتالوج من القسم أدناه واحفظ الربط؛ لا يوجد رصيد مالي موثق للرابعة.</p><p v-if="error" class="admin-form-error" role="alert">{{error}}</p><p v-if="message" role="status">{{message}}</p></section>`
};
