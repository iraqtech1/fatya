import {computed,ref} from 'vue/dist/vue.esm-bundler.js';
import {store,connection,saveAdmin} from './store.js';
import {connected} from './api.js';
import {prepareSlide} from './slides.js';
import {webSlideRows,webSlideCards} from './web-slides.js';
import CardDeck from './CardDeck.js';
export default {
  components:{CardDeck},
  setup(){
    const rows=computed(()=>webSlideRows(store.settings,store.products));
    const cards=computed(()=>webSlideCards(store.settings,store.products));
    const products=computed(()=>store.products.filter(p=>p.active));
    const busy=ref(false),message=ref(''),error=ref(false),productId=ref('');let editRevision;
    async function commit(next){
      if(connected)await saveAdmin('webSlides',next,editRevision);
      else {localStorage.setItem('fatya-web-slides-v1',JSON.stringify(next));store.settings.webSlides=next;}
    }
    async function action(task){if(busy.value)return;editRevision=connection.revision;busy.value=true;message.value='';error.value=false;try{await task();message.value='تم حفظ سلايدر الموقع';}catch(e){message.value=e.message;error.value=true;}finally{busy.value=false;}}
    async function upload(event,index=null){const file=event.target.files?.[0];event.target.value='';if(!file)return;await action(async()=>{if(index===null&&(!productId.value||rows.value.length>=10))throw Error('اختر البطاقة المرتبطة بالصورة والحد الأقصى 10 صور');const image=await prepareSlide(file);const next=rows.value.map(s=>({...s}));if(index===null)next.push({...image,productId:Number(productId.value)});else next[index]={...next[index],src:image.src,name:image.name};await commit(next);});}
    function remove(index){return action(()=>commit(rows.value.filter((s,i)=>i!==index)));}
    function move(index,step){return action(()=>{const next=rows.value.map(s=>({...s})),to=index+step;if(to<0||to>=next.length)return;[next[index],next[to]]=[next[to],next[index]];return commit(next);});}
    function link(index,value){return action(()=>{const next=rows.value.map(s=>({...s}));next[index].productId=Number(value);return commit(next);});}
    return {rows,cards,products,busy,message,error,productId,upload,remove,move,link};
  },
  template:`<section class="admin-panel slider-manager"><div class="admin-panel-title"><h2>سلايدر الموقع</h2><span>{{rows.length}} / 10</span></div><p>صور الكارتات المتقلبة بأعلى متجر الويب. تغييرات هذا القسم مستقلة عن صور المنتجات وسلايدر التطبيق. القياس المقترح 1200 × 750 بكسل؛ تظهر الصورة كاملة دون قص.</p><div class="admin-toolbar"><select v-model="productId" aria-label="البطاقة المرتبطة بالصورة الجديدة" :disabled="busy"><option value="">اختر البطاقة عند الضغط على الصورة</option><option v-for="p in products" :key="p.id" :value="p.id">{{p.name}}</option></select><label class="admin-primary slider-upload">إضافة صورة<input type="file" accept="image/jpeg,image/png,image/webp,image/gif" :disabled="busy||!productId||rows.length>=10" @change="upload($event)"/></label></div><p v-if="message" role="status" :class="{'admin-form-error':error}">{{message}}</p><div class="slider-image-list"><article v-for="(slide,i) in rows" :key="slide.id"><img :src="slide.src" :alt="slide.name" style="object-fit:contain;aspect-ratio:1.6"/><div><b>{{i+1}}. {{slide.name}}</b><select :value="slide.productId" :aria-label="'البطاقة المرتبطة بالصورة '+(i+1)" :disabled="busy" @change="link(i,$event.target.value)"><option v-for="p in products" :key="p.id" :value="p.id">{{p.name}}</option></select><div class="slider-item-actions"><label class="admin-secondary slider-upload">تغيير الصورة<input type="file" accept="image/jpeg,image/png,image/webp,image/gif" :disabled="busy" @change="upload($event,i)"/></label><button class="admin-secondary" :disabled="busy||i===0" @click="move(i,-1)">تقديم</button><button class="admin-secondary" :disabled="busy||i===rows.length-1" @click="move(i,1)">تأخير</button><button class="admin-secondary" :disabled="busy" @click="remove(i)">حذف</button></div></div></article></div><p v-if="!rows.length">السلايدر مخفي من الموقع؛ أضف صورة لإظهاره.</p><h3 v-if="cards.length">معاينة الكارتات</h3><div v-if="cards.length" class="web-premium" style="max-width:680px;margin:auto"><CardDeck :products="cards" variant="web"/></div></section>`
};
