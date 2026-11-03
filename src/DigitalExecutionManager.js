import ManualPaymentApproval from './ManualPaymentApproval.js';
import {ref,onMounted} from 'vue/dist/vue.esm-bundler.js';
import {api} from './api.js';
import {store,refreshStore} from './store.js';
import PagedList from './PagedList.js';
export default {
 components:{ManualPaymentApproval,PagedList},
 setup(){const rows=ref([]),busy=ref(false),error=ref(''),message=ref(''),pages=ref({});
  async function load(){await refreshStore();rows.value=(await api('/admin/procurement/digital')).executions;}
  async function run(fn){if(busy.value)return;busy.value=true;error.value='';message.value='';try{await fn();}catch(e){error.value=e.message;}finally{busy.value=false;}}
  function reconcile(r){return run(async()=>{const result=await api('/admin/procurement/digital/reconcile',{orderId:r.orderId,page:Number(pages.value[r.orderId]||1)});message.value=result.message||(result.state==='delivered'?'تم التحقق من التسليم':'بقيت العملية للمراجعة');if(result.companyPurchaseFound)message.value+=' — وُجد شراء بالسجل: '+result.references.join(', ');await refreshStore();await load();});}
  const label=s=>({awaiting_payment:'بانتظار الدفع',queued:'بانتظار التنفيذ',processing:'قيد التنفيذ',hold_started:'طلب حجز',held:'محجوزة لدى الشركة',purchase_started:'أُرسل الشراء',delivered:'تم التنفيذ',review:'تحتاج مراجعة',expired:'انتهى الحجز',cancelled:'ملغية'}[s]||s);
  onMounted(()=>run(load));return {store,rows,busy,error,message,pages,load,run,reconcile,label};},
 template:`<section class="admin-panel"><h2>عمليات خدمات العملاء</h2><button class="admin-secondary" @click="run(load)" :disabled="busy">تحديث العمليات</button><p v-if="!rows.length">لا توجد عمليات خدمات بعد.</p><PagedList :items="rows" :size="10" admin v-slot="{items}"><article v-for="r in items" :key="r.orderId" class="proc-code-row"><h3>{{r.provider==='topup'?'TOPUP':'الرابعة'}} — {{label(r.state)}}</h3><p dir="ltr">{{r.orderId}}</p><ManualPaymentApproval v-if="store.orders.find(o=>o.id===r.orderId)" :order="store.orders.find(o=>o.id===r.orderId)" @saved="run(load)"/><p>مرجع الشركة {{r.reference||'غير مؤكد'}} • التكلفة الفعلية {{r.actualCost??'غير مثبتة'}} IQD</p><p>لقطة التكلفة {{r.quotedCost}} IQD — {{r.updatedAt}}</p><template v-if="r.state==='review'"><label v-if="r.provider==='rabiaa'">صفحة سجل الشركة<input v-model="pages[r.orderId]" type="number" min="1" max="100" :disabled="busy"/></label><button v-permit="'digital.reconcile'" class="admin-secondary" @click="reconcile(r)" :disabled="busy">فحص الأدلة أو سجل الشركة فقط</button></template></article></PagedList><p v-if="error" role="alert">{{error}}</p><p v-if="message" role="status">{{message}}</p></section>`
};
