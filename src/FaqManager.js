import {ref,computed} from 'vue/dist/vue.esm-bundler.js';
import {store,connection,saveAdmin} from './store.js';
import {connected} from './api.js';
import {faqRows} from './faqs.js';
import {Plus,Pencil,Trash2,ArrowUp,ArrowDown} from 'lucide-vue-next';
export default {
  components:{Plus,Pencil,Trash2,ArrowUp,ArrowDown},
  setup(){
    const rows=computed(()=>faqRows(store.settings)),editing=ref(null),question=ref(''),answer=ref(''),busy=ref(false),error=ref(''),message=ref('');let editRevision;
    function reset(){editing.value=null;question.value='';answer.value='';editRevision=undefined;}
    function edit(item){editing.value=item.id;question.value=item.question;answer.value=item.answer;editRevision=connection.revision;error.value='';message.value='';}
    async function save(next,revision=connection.revision){
      if(connected)await saveAdmin('faqs',next,revision);
      else {localStorage.setItem('fatya-faqs-v1',JSON.stringify(next));store.settings.faqs=next;}
      message.value='تم حفظ الإجابات السريعة';
    }
    async function run(task){if(busy.value)return;busy.value=true;error.value='';message.value='';try{await task();}catch(e){error.value=e.message;}finally{busy.value=false;}}
    function submit(){return run(async()=>{const q=question.value.trim(),a=answer.value.trim();if(!q||!a)throw Error('اكتب السؤال والجواب');if(!editing.value&&rows.value.length>=30)throw Error('الحد الأقصى 30 سؤالاً');const item={id:editing.value||crypto.randomUUID(),question:q,answer:a},next=rows.value.map(f=>({...f}));if(editing.value){const index=next.findIndex(f=>f.id===editing.value);if(index<0)throw Error('السؤال غير موجود حدّث الصفحة');next[index]=item;}else next.push(item);await save(next,editing.value?editRevision:connection.revision);reset();});}
    function move(id,step){return run(async()=>{const next=rows.value.map(f=>({...f})),index=next.findIndex(f=>f.id===id),to=index+step;if(index<0||to<0||to>=next.length)return;[next[index],next[to]]=[next[to],next[index]];await save(next);});}
    function remove(id){return run(async()=>{await save(rows.value.filter(f=>f.id!==id));if(editing.value===id)reset();});}
    return {rows,editing,question,answer,busy,error,message,reset,edit,submit,move,remove};
  },
  template:`<section class="admin-panel admin-settings"><div class="admin-panel-title"><h2>الإجابات السريعة</h2><span>{{rows.length}} / 30</span></div><p>الأسئلة والأجوبة الظاهرة في صفحة الدعم بالموقع والتطبيق. الحفظ ينشر التغييرات على جميع الأجهزة.</p><form class="admin-dialog-form" @submit.prevent="submit"><h3>{{editing?'تعديل السؤال':'إضافة سؤال وجواب'}}</h3><label>السؤال<input v-model="question" required maxlength="200" :disabled="busy" placeholder="اكتب السؤال"/></label><label>الجواب<textarea v-model="answer" required maxlength="4000" rows="4" :disabled="busy" placeholder="اكتب الجواب"></textarea></label><div><button class="admin-primary" :disabled="busy">{{busy?'جاري الحفظ':editing?'حفظ التعديل':'إضافة السؤال'}}</button> <button v-if="editing" type="button" class="admin-secondary" :disabled="busy" @click="reset">إلغاء التعديل</button></div></form><p v-if="error" role="alert" class="admin-form-error">{{error}}</p><p v-if="message" role="status">{{message}}</p><div v-if="!rows.length" class="admin-empty">لا توجد أسئلة؛ أضف أول سؤال من النموذج أعلاه.</div><article v-for="(item,index) in rows" :key="item.id" class="admin-ticket"><div><h3>{{index+1}}. {{item.question}}</h3><p style="white-space:pre-wrap">{{item.answer}}</p><div class="admin-customer-actions"><button class="admin-row-action" :disabled="busy" @click="edit(item)"><Pencil :size="16"/> تعديل</button><button class="admin-row-action" :disabled="busy||index===0" @click="move(item.id,-1)" :aria-label="'تقديم السؤال '+(index+1)"><ArrowUp :size="16"/> تقديم</button><button class="admin-row-action" :disabled="busy||index===rows.length-1" @click="move(item.id,1)" :aria-label="'تأخير السؤال '+(index+1)"><ArrowDown :size="16"/> تأخير</button><button class="admin-row-action" :disabled="busy" @click="remove(item.id)" style="color:#b13e3e"><Trash2 :size="16"/> حذف</button></div></div></article></section>`
};
