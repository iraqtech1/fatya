import {connected} from './api.js';
import {ref} from 'vue/dist/vue.esm-bundler.js';
import {slides as topSlides,middleSlides,MAX_SLIDES,prepareSlide,saveSlides} from './slides.js';
import BannerSlider from './BannerSlider.js';
export default {
  components:{BannerSlider},
  props:{placement:{type:String,default:'top'}},
  setup(props){
    const slides=props.placement==='middle'?middleSlides:topSlides;
    const interval=props.placement==='middle'?2000:6500;
    let saving=false;
    const busy=ref(false),message=ref(''),error=ref(false);
    async function commit(next){if(busy.value&&saving)return;busy.value=true;saving=true;try{await saveSlides(next,props.placement);error.value=false;message.value='تم حفظ السلايدر';}catch(e){error.value=true;message.value=e.message;}finally{busy.value=false;saving=false;}}
    async function upload(event){
      const files=Array.from(event.target.files||[]);event.target.value='';if(!files.length)return;
      message.value='';error.value=false;
      if(files.length+slides.length>MAX_SLIDES){error.value=true;message.value='تگدر تضيف لحد 10 صور فقط';return;}
      busy.value=true;try{const next=[...slides];for(const file of files)next.push(await prepareSlide(file));await commit(next);}catch(e){error.value=true;message.value=e.message||'تعذّرت قراءة الصورة';}finally{busy.value=false;}
    }
    function remove(i){commit(slides.filter((_,n)=>n!==i));}
    function reorder(i,step){const next=[...slides],j=i+step;if(j<0||j>=next.length)return;[next[i],next[j]]=[next[j],next[i]];commit(next);}
    return {connected,slides,interval,busy,message,error,upload,remove,reorder};
  },
  template:`<section class="admin-panel slider-manager"><div class="admin-panel-title"><h2>صور السلايدر</h2><span>{{slides.length}} / 10</span></div><p>أضف صور العروض؛ تتبدّل تلقائياً كل {{interval/1000}} ثانية ويدعم السحب. الأفضل صور أفقية بنسبة 16:6.</p><label class="admin-primary slider-upload">{{busy?'جاري تجهيز الصور…':'إضافة صور'}}<input type="file" multiple accept="image/jpeg,image/png,image/webp,image/gif" :disabled="busy||slides.length>=10" @change="upload"/></label><p class="slider-storage-note">يدعم GIF وWebP المتحركة حتى 2 ميگابايت. {{connected?'الصور محفوظة بالخادم وتظهر على جميع أجهزة التطبيق.':'الصور محفوظة بهذا المتصفح.'}}</p><p v-if="message" role="status" :class="{'admin-form-error':error}">{{message}}</p><div class="slider-image-list"><article v-for="(slide,i) in slides" :key="slide.id"><img :src="slide.src" :alt="slide.name"/><div><b>{{i+1}}. {{slide.name}}</b><div class="slider-item-actions"><button class="admin-secondary" :disabled="busy||i===0" @click="reorder(i,-1)" :aria-label="'تقديم الصورة '+(i+1)">تقديم</button><button class="admin-secondary" :disabled="busy||i===slides.length-1" @click="reorder(i,1)" :aria-label="'تأخير الصورة '+(i+1)">تأخير</button><button class="admin-secondary" :disabled="busy" @click="remove(i)" :aria-label="'حذف الصورة '+(i+1)">حذف</button></div></div></article></div><div v-if="!slides.length" class="admin-empty">أضف أول صورة حتى يظهر السلايدر بالرئيسية.</div><h3 v-if="slides.length">معاينة السلايدر</h3><BannerSlider :placement="placement" :interval="interval"/></section>`
};
