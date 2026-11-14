import {ref,onMounted} from 'vue/dist/vue.esm-bundler.js';
import {api} from './api.js';
import {refreshStore} from './store.js';
export default {
 props:{catalogBusy:Boolean},emits:['saved'],
 setup(props,{emit}){
  const rows=ref([]),busy=ref(false),error=ref(''),message=ref(''),editing=ref(null);
  async function load(){const r=await api('/admin/procurement/digital');rows.value=r.connections.map(c=>({...c,name:c.id==='topup'?'TOPUP':c.name,credential:'',reason:''}));}
  async function run(task){if(busy.value||props.catalogBusy)return;busy.value=true;error.value='';message.value='';try{await task();}catch(e){error.value=e.message;}finally{busy.value=false;}}
  function edit(c){editing.value=c.id;error.value='';message.value='';}
  function cancel(){editing.value=null;return run(load);}
  function save(c){return run(async()=>{const tokenUpdated=!!c.credential.trim();await api('/admin/procurement/digital/connections',{provider:c.id,version:c.version,active:c.active,credential:c.credential,reason:c.reason,...c.id==='rabiaa'?{ownerCity:c.ownerCity||''}:{}},'PUT');c.credential='';editing.value=null;await refreshStore();await load();emit('saved',{provider:c.id,refresh:c.active,tokenUpdated});message.value='تم حفظ التوكن';});}
  onMounted(()=>run(load));return {rows,busy,error,message,editing,edit,cancel,save};
 },
 template:`<section class="admin-panel"><h2>اتصال الشركات</h2><article v-for="c in rows" :key="c.id" class="proc-code-row"><div class="admin-panel-title"><h3>{{c.name}}</h3><span class="admin-status" :class="{warning:!c.hasCredential||!c.active}">{{c.hasCredential?(c.active?'التوكن محفوظ · الاتصال مفعّل':'التوكن محفوظ · الاتصال موقوف'):'التوكن غير مضاف'}}</span><button v-permit="'digital.connections_update'" v-if="editing!==c.id" type="button" class="admin-secondary" @click="edit(c)" :disabled="busy||catalogBusy">{{c.hasCredential?'تعديل التوكن':'إضافة التوكن'}}</button></div><form v-permit="'digital.connections_update'" v-if="editing===c.id" @submit.prevent="save(c)"><label>توكن {{c.name}}<input v-model="c.credential" type="password" autocomplete="off" dir="ltr" maxlength="4096" :required="!c.hasCredential" :disabled="busy||catalogBusy"/></label><label v-if="c.id==='rabiaa'">محافظة صاحب التوكن<input v-model="c.ownerCity" maxlength="100" :required="c.active" :disabled="busy||catalogBusy" placeholder="مثال: بغداد"/></label><label>سبب التعديل<input v-model="c.reason" maxlength="500" :disabled="busy||catalogBusy"/></label><label class="admin-checkbox"><input v-model="c.active" type="checkbox" :disabled="busy||catalogBusy"/> تفعيل اتصال الشركة</label><div class="proc-actions"><button class="admin-primary" type="submit" :disabled="busy||catalogBusy">حفظ التوكن</button><button class="admin-secondary" type="button" @click="cancel" :disabled="busy||catalogBusy">إلغاء</button></div></form></article><p v-if="error" class="admin-form-error" role="alert">{{error}}</p><p v-if="message" role="status">{{message}}</p></section>`
};
