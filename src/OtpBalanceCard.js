import {ref,onMounted,onBeforeUnmount} from 'vue/dist/vue.esm-bundler.js';
import {api,connected} from './api.js';
import {MessageCircle,RefreshCw} from 'lucide-vue-next';
import './otp-balance.css';
export default {
  components:{MessageCircle,RefreshCw},
  setup(){
    const balance=ref(null),busy=ref(false),error=ref(''),checkedAt=ref('');let timer;
    async function refresh(force=false){if(busy.value||!connected)return;busy.value=true;error.value='';try{const result=await api('/admin/otp/balance'+(force?'?refresh=1':''));balance.value=result.availableMessages;checkedAt.value=new Intl.DateTimeFormat('ar-IQ',{hour:'2-digit',minute:'2-digit',timeZone:'Asia/Baghdad'}).format(new Date(result.checkedAt));}catch{error.value='تعذّر جلب الرصيد حاول مرة أخرى';}finally{busy.value=false;}}
    onMounted(()=>{refresh();timer=setInterval(()=>{if(!document.hidden)refresh();},60000);});
    onBeforeUnmount(()=>clearInterval(timer));
    return {connected,balance,busy,error,checkedAt,refresh};
  },
  template:`<section class="admin-panel admin-otp-card" aria-label="رصيد رسائل OTP"><div><span>رصيد رسائل التحقق OTP</span><MessageCircle :size="20"/></div><strong dir="ltr">{{!connected||error?'—':balance===null?'…':new Intl.NumberFormat('en-US').format(balance)}} <small>رسالة</small></strong><p v-if="!connected">يتوفر الرصيد على الاستضافة المتصلة</p><p v-else-if="error" role="alert">{{error}}</p><p v-else-if="balance===0" class="otp-empty">الرصيد نفد · عبّئ رصيد تطبيق fatyh في تأكيد</p><p v-else>رسائل واتساب المتاحة للتحقق</p><footer><small>{{checkedAt?'آخر تحديث '+checkedAt:'تأكيد · واتساب'}}</small><button type="button" @click="refresh(true)" :disabled="busy||!connected" aria-label="تحديث رصيد رسائل OTP"><RefreshCw :size="14"/>{{busy?'جاري التحديث':'تحديث الرصيد'}}</button></footer></section>`
};
