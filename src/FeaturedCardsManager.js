import {computed,ref,watch} from 'vue/dist/vue.esm-bundler.js';
import {store,connection,saveAdmin} from './store.js';
import {connected} from './api.js';
import {prepareSlide} from './slides.js';
import {FEATURED_KEY,featuredConfig,featuredProducts} from './featured-cards.js';
import FeaturedCards from './FeaturedCards.js';
export default {
  components:{FeaturedCards},
  setup(){
    const draft=ref(),revision=ref(),baseline=ref(''),busy=ref(false),error=ref(''),message=ref(''),productId=ref('');
    function reset(){draft.value=JSON.parse(JSON.stringify(featuredConfig(store.settings,store.products,store.categories)));baseline.value=JSON.stringify(draft.value);revision.value=connection.revision;error.value='';message.value='';}
    reset();
    const dirty=computed(()=>JSON.stringify(draft.value)!==baseline.value);
    watch(()=>JSON.stringify(draft.value),()=>{message.value='';},{flush:'sync'});
    watch(()=>[connection.revision,JSON.stringify(store.settings.featuredCards)],()=>{if(JSON.stringify(featuredConfig(store.settings,store.products,store.categories))===baseline.value)revision.value=connection.revision;},{flush:'sync'});
    const rows=computed(()=>draft.value.productIds.map(id=>({id,product:store.products.find(p=>String(p.id)===String(id))}))),available=computed(()=>store.products.filter(p=>p.active&&!draft.value.productIds.some(id=>String(id)===String(p.id)))),cards=computed(()=>featuredProducts(draft.value,store.products,store.categories));
    function add(){if(!productId.value||draft.value.productIds.length>=10)return;const p=available.value.find(p=>String(p.id)===String(productId.value));if(p)draft.value.productIds.push(p.id);productId.value='';message.value='';}
    function remove(index){draft.value.productIds.splice(index,1);message.value='';}
    function move(index,step){const to=index+step;if(to<0||to>=rows.value.length)return;const ids=draft.value.productIds;[ids[index],ids[to]]=[ids[to],ids[index]];message.value='';}
    async function upload(event){const file=event.target.files?.[0];event.target.value='';if(!file)return;busy.value=true;error.value='';message.value='';try{draft.value.backgroundImage=(await prepareSlide(file)).src;}catch(e){error.value=e.message;}finally{busy.value=false;}}
    async function save(){if(busy.value)return;busy.value=true;error.value='';message.value='';try{
      const next=JSON.parse(JSON.stringify(draft.value));
      if(!Number.isInteger(next.interval)||next.interval<0||(next.interval>0&&next.interval<1000)||next.interval>30000)throw Error('اختر مدة بين 1 و30 ثانية أو صفر لإيقاف الحركة التلقائية');
      if(connected)await saveAdmin('featuredCards',next,revision.value);
      else {localStorage.setItem(FEATURED_KEY,JSON.stringify(next));store.settings.featuredCards=next;}
      reset();message.value='تم حفظ الكارتات المميزة';
    }catch(e){error.value=e.status===409?'تغيّرت البيانات؛ اضغط إعادة تحميل الإعدادات ثم أعد التعديل':e.message;}finally{busy.value=false;}}
    return {draft,dirty,busy,error,message,productId,rows,available,cards,reset,add,remove,move,upload,save};
  },
  template:`<section class="admin-panel featured-manager"><form class="featured-form" @submit.prevent="save">
    <div class="featured-toolbar"><div><h2>إعدادات العرض</h2><span>{{draft.visible?'ظاهر بالتطبيق':'مخفي بالتطبيق'}}</span></div><div class="featured-actions"><button v-permit="'featured_cards.update'" class="admin-primary" :disabled="busy">{{busy?'جاري الحفظ':'حفظ التغييرات'}}</button><button type="button" class="admin-secondary" :disabled="busy" @click="reset">إعادة تحميل الإعدادات</button></div></div>
    <p v-if="error" role="alert" class="admin-form-error">{{error}}</p><p v-if="message" role="status" class="featured-save-status">{{message}}</p><p v-else-if="dirty" role="status" class="featured-save-status">عندك تعديلات غير محفوظة</p>
    <fieldset :disabled="busy" v-readonly-access="'featured_cards.update'" class="featured-general">
      <div class="featured-visibility-options">
      <button type="button" class="admin-secondary featured-visibility" :aria-pressed="draft.visible" @click="draft.visible=!draft.visible">{{draft.visible?'إخفاء القسم من التطبيق':'إظهار القسم بالتطبيق'}}</button>
      <button type="button" class="admin-secondary featured-visibility" :aria-pressed="draft.webVisible" @click="draft.webVisible=!draft.webVisible">{{draft.webVisible?'إخفاء الكارتات من موقع الويب':'إظهار الكارتات بموقع الويب'}}</button></div>
      <div class="featured-text-grid"><label class="featured-field">العنوان<input v-model="draft.title" maxlength="120"/></label><label class="featured-field">تكملة العنوان الملوّنة<input v-model="draft.accent" maxlength="120"/></label><label class="featured-field">النص العلوي<input v-model="draft.label" maxlength="160"/></label><label class="featured-field featured-speed">الوقت بين البطاقات (بالثواني)<input type="number" :value="draft.interval/1000" @input="draft.interval=Math.round(Number($event.target.value)*1000)" min="0" max="30" step="0.1" required/><small>من 1 إلى 30 ثانية؛ صفر يوقف الحركة التلقائية.</small></label></div>
    </fieldset>
    <div class="featured-layout"><section class="featured-appearance"><h3>الخلفية والمعاينة</h3>
      <fieldset :disabled="busy" v-readonly-access="'featured_cards.update'" class="featured-background-tools"><div class="featured-colors"><label class="featured-field">بداية اللون<input type="color" aria-label="بداية لون الخلفية" v-model="draft.backgroundStart"/></label><label class="featured-field">نهاية اللون<input type="color" aria-label="نهاية لون الخلفية" v-model="draft.backgroundEnd"/></label></div><div class="featured-image-actions"><label class="admin-secondary slider-upload">اختيار صورة خلفية<input type="file" accept="image/jpeg,image/png,image/webp,image/gif" @change="upload"/></label><button v-if="draft.backgroundImage" type="button" class="admin-secondary" @click="draft.backgroundImage=''">إزالة صورة الخلفية</button></div></fieldset>
      <p class="featured-preview-note">معاينة مباشرة قبل الحفظ</p><p v-if="!draft.visible" class="featured-preview-note">القسم مخفي بالتطبيق؛ المعاينة هنا فقط.</p><div class="app-shell featured-preview"><FeaturedCards :config="draft" :products="cards" preview/></div>
    </section><section class="featured-selection"><fieldset :disabled="busy" v-readonly-access="'featured_cards.update'"><h3>البطاقات وترتيبها <small>{{rows.length}} / 10</small></h3><div class="featured-add"><select v-model="productId" aria-label="اختر بطاقة مميزة"><option value="">اختر بطاقة</option><option v-for="p in available" :key="p.id" :value="p.id">{{p.name}}</option></select><button type="button" class="admin-secondary" :disabled="!productId||rows.length>=10" @click="add">إضافة بطاقة</button></div><p class="featured-preview-note">تظهر البطاقات والتصنيفات الفعّالة فقط.</p><ol class="featured-order"><li v-for="(row,i) in rows" :key="row.id"><span>{{row.product?.name||'بطاقة محذوفة'}} <small v-if="!row.product?.active">(غير متاحة)</small></span><div><button type="button" class="admin-secondary" :disabled="i===0" @click="move(i,-1)" :aria-label="'تقديم '+(row.product?.name||row.id)">تقديم</button><button type="button" class="admin-secondary" :disabled="i===rows.length-1" @click="move(i,1)" :aria-label="'تأخير '+(row.product?.name||row.id)">تأخير</button><button type="button" class="admin-secondary" @click="remove(i)">إزالة</button></div></li></ol><p v-if="!rows.length">لا توجد بطاقات مختارة.</p></fieldset></section></div>
  </form></section>`
};
