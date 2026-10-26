import {computed,ref,watch} from 'vue/dist/vue.esm-bundler.js';
import {Search,UserRound,ArrowLeft,ArrowUpLeft,ShoppingBag,Headphones,Grid2X2,Gamepad2,Wifi,Ticket,Smartphone,CreditCard,Gift,Joystick,Coins,Music2,Globe2} from 'lucide-vue-next';
import StoreBrandMark from './StoreBrandMark.js';
import PagedList from './PagedList.js';
import CardDeck from './CardDeck.js';
import {t,language,denominations} from './i18n.js';
import StorePreferences from './StorePreferences.js';
import {routeHref} from './routing.js';
import './web-store.css';

const decorationIcons=[Gamepad2,Wifi,Ticket,Smartphone,CreditCard,Gift,Joystick,Coins,Music2,Globe2,Headphones,ShoppingBag];
const heroDecorations=[
 [8,13,52,-12],[27,9,42,9],[46,16,60,-9],[66,10,46,13],[83,20,54,-15],[96,9,40,8],
 [5,42,44,12],[23,36,60,-14],[43,44,46,8],[62,35,54,-10],[81,48,42,14],[96,42,54,-7],
 [21,69,58,10],[29,62,44,-8],[47,71,52,13],[67,63,42,-13],[86,72,56,9],[97,66,40,-10],
 [5,90,42,-8],[24,88,52,14],[43,94,42,-12],[62,86,58,8],[79,94,44,-9],[94,89,48,12]
].map(([x,y,size,angle],index)=>({icon:decorationIcons[index%decorationIcons.length],x,y,size,angle,duration:8+index%6,delay:-index*.7}));

export default {
 components:{CardDeck,StorePreferences,Search,UserRound,ArrowLeft,ArrowUpLeft,ShoppingBag,Headphones,Grid2X2,StoreBrandMark,PagedList},
 props:{products:{type:Array,default:()=>[]},categories:{type:Array,default:()=>[]},session:Object,settings:Object,page:String,category:String,query:String,loading:Boolean,error:String},
 emits:['browse','select','navigate','auth','refresh'],
 setup(props,{emit}){
  const search=ref(props.query||''),sort=ref('default');
  watch(()=>props.query,value=>{search.value=value||'';});
  const browsing=computed(()=>['home','cards'].includes(props.page));
  const showingHome=computed(()=>props.page==='home'&&!props.query&&props.category==='الكل');
  const result=computed(()=>{
   const query=(props.query||'').trim().toLocaleLowerCase();
   const rows=props.products.filter(p=>(props.category==='الكل'||p.category===props.category)&&(!query||`${p.name} ${p.en} ${t(p.name)}`.toLocaleLowerCase().includes(query)));
   if(sort.value==='price')rows.sort((a,b)=>Math.min(...a.prices)-Math.min(...b.prices));
   if(sort.value==='name')rows.sort((a,b)=>t(a.name).localeCompare(t(b.name),language.value==='ku'?'ckb':language.value));
   return rows;
  });
  const heroProducts=computed(()=>['Steam','KoreK','Zain'].map(name=>props.products.find(p=>p.en?.toLowerCase()===name.toLowerCase())).filter(Boolean));
  const brands=computed(()=>props.products.filter(p=>['zain','korek','steam','apple','ps','asiacell'].includes(p.en?.toLowerCase())).slice(0,6));
  const number=n=>new Intl.NumberFormat('en-US').format(n);
  function browse(category='الكل'){emit('browse',category,search.value);}
  return {heroDecorations,t,language,denominations,routeHref,search,sort,browsing,showingHome,result,heroProducts,brands,number,browse};
 },
 template:`<div class="web-premium" :dir="language==='en'?'ltr':'rtl'">
 <header class="web-header web-container">
  <a class="web-brand" href="./" @click.prevent="$emit('navigate','home')" :aria-label="t('فاتيا — الرئيسية')"><StoreBrandMark/><span><strong>{{t(settings.name||'فاتيا')}}</strong><small>YOUR DIGITAL CHOICE</small></span></a>
  <form class="web-search" role="search" @submit.prevent="browse()"><Search :size="20" aria-hidden="true"/><input v-model="search" type="search" :placeholder="t('اكتشف بطاقتك')" :aria-label="t('البحث في متجر الويب')"/><button type="submit" :aria-label="t('بحث')"><ArrowLeft :size="19"/></button></form>
  <div class="web-account-actions"><template v-if="!session"><button @click="$emit('auth','login')"><UserRound :size="20"/>{{t('تسجيل الدخول')}}</button><button class="web-register" @click="$emit('auth','register')">{{t('إنشاء حساب')}}</button></template><template v-else><button @click="$emit('navigate','account')"><UserRound :size="20"/>{{session.name}}</button><button @click="$emit('navigate','orders')"><ShoppingBag :size="20"/>{{t('طلباتي')}}</button></template></div>
 <StorePreferences/>
 </header>
 <nav class="web-nav web-container" :aria-label="t('تصنيفات متجر الويب')"><button :aria-pressed="category==='الكل'&&browsing" @click="search='';browse('الكل')">{{t('كل البطاقات')}}</button><button v-for="c in categories" :key="c.id" :aria-pressed="category===c.name&&browsing" @click="search='';browse(c.name)">{{t(c.name)}}</button><button @click="$emit('navigate','support')"><Headphones :size="17"/>{{t('المساعدة')}}</button></nav>
 <main v-if="browsing" class="web-container web-shop-main">
  <section v-if="showingHome" class="web-editorial-hero"><div class="web-hero-decoration" aria-hidden="true"><span v-for="(mark,index) in heroDecorations" :key="index" class="web-hero-doodle" :style="{'--doodle-x':mark.x+'%','--doodle-y':mark.y+'%','--doodle-size':mark.size+'px','--doodle-angle':mark.angle+'deg','--doodle-duration':mark.duration+'s','--doodle-delay':mark.delay+'s'}"><component :is="mark.icon" :stroke-width="1.35"/></span></div><div class="web-hero-copy"><span class="web-kicker">FATYA / DIGITAL STORE</span><h1>{{t('عالمك الرقمي،')}}<br><em>{{t('باختيارك.')}}</em></h1><p>{{t('من رصيدك اليومي إلى ألعابك المفضلة. اكتشف البطاقات واختَر الفئة اللي تناسبك.')}}</p><a class="web-primary" href="#web-collection">{{t('اكتشف البطاقات')}}<ArrowLeft :size="19"/></a></div><div class="web-hero-deck"><CardDeck v-if="heroProducts.length" :products="heroProducts" variant="web" @select="$emit('select',$event)"/></div></section>
  <div v-if="showingHome&&brands.length" class="web-brand-row" :aria-label="t('شركات البطاقات')"><span v-for="p in brands" :key="p.id">{{p.en}}</span></div>
  <section id="web-collection" class="web-collection" :aria-label="t('مجموعة البطاقات')"><div class="web-section-heading"><div><span class="web-kicker">THE COLLECTION</span><h2>{{showingHome?t('اختيارات فاتيا'):category==='الكل'?t('كل البطاقات'):t(category)}}</h2><p v-if="query">{{t('نتائج البحث عن')}} «{{query}}»</p></div><label class="web-sort">{{t('ترتيب البطاقات')}}<select v-model="sort" :aria-label="t('ترتيب بطاقات الويب')"><option value="default">{{t('الترتيب الافتراضي')}}</option><option value="price">{{t('السعر من الأقل')}}</option><option value="name">{{t('حسب الاسم')}}</option></select></label></div>
   <p v-if="loading" class="web-feedback" role="status">{{t('جارٍ تحميل البطاقات…')}}</p><p v-if="error" class="web-feedback" role="alert">{{error}} <button @click="$emit('refresh')">{{t('إعادة المحاولة')}}</button></p>
   <PagedList :items="result" :size="12" :reset-key="query+category+sort" v-slot="{items:rows}"><div class="web-products"><article v-for="p in rows" :key="p.id" class="web-product"><button class="web-product-art" @click="$emit('select',p)" :aria-label="t('تفاصيل')+' '+t(p.name)"><img :src="p.image" :alt="p.name" loading="lazy" width="1200" height="750"/></button><div class="web-product-heading"><h3>{{t(p.name)}}</h3><span>{{t(p.category)}} · {{denominations(p.values.length)}}</span></div><div class="web-product-bottom"><span><small>{{t('ابتداءً من')}}</small> <b>{{number(Math.min(...p.prices))}}</b> <small>{{t('د.ع')}}</small></span><button @click="$emit('select',p)">{{t('اختر الفئة')}}<ArrowUpLeft :size="16"/></button></div></article></div></PagedList>
   <div v-if="!loading&&!error&&!result.length" class="web-empty"><Grid2X2 :size="32"/><h3>{{t('ما لقينا بطاقة بهالاسم')}}</h3><button class="web-primary" @click="search='';browse('الكل')">{{t('عرض كل البطاقات')}}</button></div>
  </section>
 </main>
 <slot v-else/>
 <footer class="web-footer web-container"><div><strong>{{t(settings.name||'فاتيا')}}</strong><p>{{t('كل بطاقاتك، بذوقك.')}}</p></div><div><button @click="$emit('navigate','support')">{{t('تواصل ويانا')}}</button><a :href="routeHref('/app')">{{t('فتح واجهة التطبيق')}}</a><span>{{t('العراق')}} · {{language==='en'?'English':language==='ku'?'کوردی':'العربية'}}</span></div></footer>
 </div>`
};
