import MasalMark from './MasalMark.js';
import {disconnectPush} from './push-notifications.js';
import {routePath,inRoute} from './routing.js';
import './viewport.js';
import {api,connected} from './api.js';
import {copyOrderCodes} from './order-delivery.js';
import {denominationAvailable,deliveredOrder,cancellableOrder,orderStateLabel,paymentStateLabel,orderTime} from './order-state.js';
import PaymentPreview from './PaymentPreview.js';
import PaymentMethods from './PaymentMethods.js';
import PagedList from './PagedList.js';
import {t,language,cardCount,applyLanguage} from './i18n.js';
import './language.css';
import {createApp,ref,computed,watch,nextTick,onMounted,onBeforeUnmount} from 'vue/dist/vue.esm-bundler.js';
import {Home,Grid2X2,ShoppingBag,Headphones,Phone,Search,ChevronLeft,X,Check,Copy,CreditCard,Gamepad2,Smartphone,Layers,User,Plus,Minus,ShieldCheck,SlidersHorizontal,Ticket,ArrowUpRight,Signal,Wifi} from 'lucide-vue-next';
import './style.css';
import './support-contact.css';
import './category-filters.css';
import './payment-methods.css';
import './mobile-navigation.css';
import CardDeck from './CardDeck.js';
import StoreCards from './StoreCards.js';
import AsiacellFlow from './AsiacellFlow.js';
import {isAsiacell} from './asiacell-services.js';
import PullToRefresh from './PullToRefresh.js';
import NavIcon from './NavIcon.js';
import MobileDock from './MobileDock.js';
import StoreHeader from './StoreHeader.js';
import AuthGate,{auth} from './AuthGate.js';
import AccountPage from './AccountPage.js';
import PwaControls from './PwaControls.js';
import Dashboard from './Dashboard.js';
import ThemeToggle from './ThemeToggle.js';
import './theme.css';
import BannerSlider from './BannerSlider.js';
import StoreBrandMark from './StoreBrandMark.js';
import SplashIntro from './SplashIntro.js';
import MarketingSite from './marketing/Site.js';
import {router} from './marketing/router.js';
import {store,connection,refreshStore,CATEGORIES,upsertCustomer,createOrder,createTicket,matchesCustomer} from './store.js';
import {restoreStoreSession,saveStoreSession} from './store-session.js';
router.afterEach(()=>applyLanguage());
import './storefront-identity.css';
import './fatya-identity.css';
import './auth-login.css';
import './payment-selection.css';
import './dark-botanical.css';
import './nav-motion-preview.css';
import './app-refinements.css';
import './dashboard-icons.css';
import './store-catalog.css';
import WebStore from './WebStore.js';
import StorePreferences from './StorePreferences.js';
import {isWebStore,accountPages} from './store-surface.js';
const StoreLayout={inheritAttrs:false,template:'<slot/>'};
const app=createApp({
components:{WebStore,AsiacellFlow,StoreCards,StoreBrandMark,PaymentMethods,PaymentPreview,PagedList,StoreHeader,MobileDock,AccountPage,PullToRefresh,SplashIntro,BannerSlider,MarketingSite,Dashboard,PwaControls,AuthGate,NavIcon,CardDeck,Home,Grid2X2,ShoppingBag,Headphones,Phone,Search,ChevronLeft,X,Check,Copy,CreditCard,Gamepad2,Smartphone,Layers,User,Plus,Minus,ShieldCheck,SlidersHorizontal,Ticket,ArrowUpRight,Signal,Wifi},
setup(){
const admin=ref(inRoute(routePath(),'/admin'));
const marketing=ref(inRoute(routePath(),'/ar'));
const webMode=ref(isWebStore()),authVisible=ref(false),authView=ref('login'),pendingCheckout=ref(null),pendingPage=ref('home');
function readRoute(){admin.value=inRoute(routePath(),'/admin');marketing.value=inRoute(routePath(),'/ar');webMode.value=isWebStore();}
const displayMode=globalThis.matchMedia?.('(display-mode: standalone)');
onMounted(()=>displayMode?.addEventListener('change',readRoute));
onBeforeUnmount(()=>displayMode?.removeEventListener('change',readRoute));
onMounted(()=>window.addEventListener('fatya:routechange',readRoute));
onBeforeUnmount(()=>window.removeEventListener('fatya:routechange',readRoute));
let previewStorage,legacyStorage;try{previewStorage=window.localStorage;}catch{}try{legacyStorage=window.sessionStorage;}catch{}
const restored=connected?null:(restoreStoreSession(previewStorage)||restoreStoreSession(legacyStorage));
if(!connected&&restored){saveStoreSession(previewStorage,restored);saveStoreSession(legacyStorage,{entered:false});}
const entered=ref(!!restored),session=ref(restored?.user||null),authRequest=ref(0);
watch(()=>[connection.admin,connection.ready,connection.customer],([isAdmin,ready,customer])=>{if(!connected||isAdmin||!ready)return;if(customer){session.value=customer;entered.value=true;}else if(session.value||entered.value){session.value=null;entered.value=false;authRequest.value++;if(webMode.value&&accountPages.has(page.value))page.value='home';}},{flush:'sync'});
if(restored?.user)upsertCustomer(restored.user);
function requestAuth(mode='login',destination='home'){authView.value=mode==='register'?'register':'login';pendingPage.value=destination;authRequest.value++;authVisible.value=true;}
async function cancelAuth(){authVisible.value=false;if(pendingCheckout.value){pendingCheckout.value=null;await returnCheckout();}}
async function enterStore(user){if(connected)await refreshStore().catch(e=>notify(e.message));session.value=user;entered.value=true;upsertCustomer(user);authVisible.value=false;if(pendingCheckout.value){paymentDraft.value=pendingCheckout.value;pendingCheckout.value=null;go('payment');}else go(webMode.value?pendingPage.value:'home');}
async function accountAccess(){if(connected){try{await disconnectPush();await api('/auth/logout',{});}catch(e){notify(e.message);return;}await refreshStore().catch(()=>{});}session.value=null;entered.value=false;paymentDraft.value=null;paymentReceipt.value='';pendingCheckout.value=null;pendingPage.value='home';authVisible.value=false;toast.value='';authRequest.value++;if(webMode.value)go('home');}
const profileError=ref('');
const supportPhoneHref=computed(()=>{const phone=store.settings.supportPhone.replace(/[٠-٩]/g,c=>String(c.charCodeAt(0)-1632)).replace(/[۰-۹]/g,c=>String(c.charCodeAt(0)-1776)).replace(/[^+0-9]/g,'');return phone?'tel:'+phone:'';});
async function saveProfile({name,email,done}){try{const updated=await auth.updateProfile({...session.value,name,email});session.value=updated;upsertCustomer(updated);profileError.value='';done();notify('تم حفظ الملف الشخصي');}catch(error){profileError.value=error.message;}}
const navMotion=ref(0);
const paymentMethod=ref('Qi'),paymentDraft=ref(null),paymentReceipt=ref('');
const navIcons={Home,Grid2X2,ShoppingBag,Headphones,User,Plus};
const page=ref(restored?.page||'home'),category=ref('الكل'),query=ref(''),selected=ref(null),quantity=ref(1),denom=ref(0),orders=computed(()=>store.orders.filter(o=>matchesCustomer(o,session.value))),toast=ref(''),subject=ref(''),message=ref(''),success=ref(false),dialog=ref(null);
watch([entered,session,page],()=>{if(!connected)saveStoreSession(previewStorage,{entered:entered.value,user:session.value,page:page.value});},{flush:'sync'});
const products=computed(()=>store.products.filter(p=>p.active&&store.categories.some(c=>c.active&&c.name===p.category)));
const homeQuery=ref('');
function productForSlide(slide){return slide&&isAsiacell({name:slide.name,en:slide.src})?products.value.find(isAsiacell)||null:null;}
const asiacellProduct=ref(null),asiacellDraft=ref(null),asiacellOrigin=ref(null);
function backFromAsiacell(){const previous=asiacellOrigin.value||{page:'home',category:'الكل',query:''};go(previous.page);category.value=previous.category;query.value=previous.query;}
function checkoutAsiacell(checkout){paymentDraft.value={...checkout,reference:'FATYA-'+crypto.randomUUID().slice(0,8).toUpperCase()};asiacellDraft.value=checkout.asiacellDraft;paymentReceipt.value='';authorizeCheckout();}
const catalogSections=computed(()=>store.categories.filter(c=>c.active).map(c=>({...c,products:products.value.filter(p=>p.category===c.name)})).filter(c=>c.products.length));
function browseCards(name='الكل',search=''){go('cards');category.value=name;query.value=search.trim();}
const myTickets=computed(()=>store.tickets.filter(t=>matchesCustomer(t,session.value)));
const number=n=>new Intl.NumberFormat('en-US').format(n);
const filtered=computed(()=>products.value.filter(p=>(category.value==='الكل'||p.category===category.value)&&(`${p.name} ${t(p.name)} ${p.en}`.toLowerCase().includes(query.value.toLowerCase()))));
const price=computed(()=>selected.value?selected.value.prices[denom.value]*quantity.value:0);
const filterCats=computed(()=>['الكل',...store.categories.filter(c=>c.active).map(c=>c.name)]);
watch(filterCats,cats=>{if(!cats.includes(category.value))category.value='الكل';});
function categoryLabel(name){const c=store.categories.find(c=>c.name===name);return language.value==='en'&&c?c.en:t(name);}
const categoryIconSet={Grid2X2,Signal,Wifi,Gamepad2,ShoppingBag,Smartphone,Layers,CreditCard,Headphones};
const categoryIcons=computed(()=>Object.fromEntries([['الكل',Grid2X2],...store.categories.map(c=>[c.name,categoryIconSet[c.icon]||Layers])]));
const previewImage=ref('');
const denomArt=computed(()=>selected.value?.denomImages?.[denom.value]||'');
const gallery=computed(()=>selected.value?[...new Set([selected.value.image,...(selected.value.denomImages||[])].filter(Boolean))]:[]);
const shownImage=computed(()=>previewImage.value||denomArt.value||selected.value?.image||'');
watch(denom,()=>{previewImage.value=''});
function selectGalleryImage(src){const index=(selected.value?.denomImages||[]).findIndex(image=>image===src);if(index>=0)denom.value=index;previewImage.value=src;}
const nav=[{id:'home',label:'الرئيسية',icon:'Home'},{id:'cards',label:'البطاقات',icon:'Grid2X2'},{id:'orders',label:'طلباتي',icon:'ShoppingBag'},{id:'support',label:'الدعم',icon:'Headphones'},{id:'account',label:'حسابي',icon:'User'}];
const mobileNav=[{id:'home',label:'الرئيسية',icon:'Home'},{id:'orders',label:'طلباتي',icon:'ShoppingBag'},{id:'cards',label:'شراء بطاقة',icon:'Plus',primary:true},{id:'support',label:'الدعم',icon:'Headphones'},{id:'account',label:'حسابي',icon:'User'}];
function go(p){if(webMode.value&&!session.value&&accountPages.has(p)){requestAuth('login',p);return;}navMotion.value++;page.value=p;query.value='';if(p==='home')category.value='الكل';nextTick(()=>{const scroller=!webMode.value&&innerWidth<=700?document.querySelector('.app-shell>main'):window;scroller?.scrollTo({top:0,behavior:innerWidth<=700?'instant':'smooth'});});}
function notify(t){toast.value=t;setTimeout(()=>toast.value='',3000)}
async function open(p){if(isAsiacell(p)){asiacellOrigin.value={page:page.value,category:category.value,query:query.value};asiacellProduct.value=p;asiacellDraft.value=null;go('asiacell');return;}selected.value=p;quantity.value=1;denom.value=0;paymentMethod.value='Qi';success.value=false;previewImage.value='';await nextTick();dialog.value.showModal();dialog.value.scrollTop=0}
function close(){dialog.value.close();selected.value=null}
function authorizeCheckout(){if(!session.value){pendingCheckout.value=paymentDraft.value;requestAuth('login','payment');return;}go('payment');}
function available(product,index){return denominationAvailable(store.products.find(p=>p.id===product?.id)||product,index,connected);}
function startCheckout(method=paymentMethod.value){if(!selected.value)return;if(!available(selected.value,denom.value)){notify('نفد مخزون هذه الفئة');return;}paymentMethod.value=method;paymentDraft.value={product:selected.value,denom:denom.value,quantity:quantity.value,amount:price.value,method,reference:'FATYA-'+crypto.randomUUID().slice(0,8).toUpperCase()};paymentReceipt.value='';close();authorizeCheckout();}
const paymentBusy=ref(false);
async function confirmPayment(){if(!paymentDraft.value||paymentReceipt.value||paymentBusy.value)return;if(!session.value){authorizeCheckout();return;}paymentBusy.value=true;const c=paymentDraft.value;try{paymentReceipt.value=(await createOrder(c.product,c.denom,c.quantity,session.value,c.method,c.reference,{telecom:c.telecom,demoOnly:c.demoOnly})).id;}catch(e){if(e.status===401){session.value=null;entered.value=false;authorizeCheckout();}else notify(e.message);}finally{paymentBusy.value=false;}}
async function returnCheckout(){const c=paymentDraft.value;if(!c)return;if(c.telecom){asiacellDraft.value=c.asiacellDraft;go('asiacell');return;}go('cards');await open(c.product);denom.value=c.denom;quantity.value=c.quantity;paymentMethod.value=c.method;}

const cancellationBusy=ref(null);
async function cancelOrder(order){if(cancellationBusy.value||!cancellableOrder(order))return;cancellationBusy.value=order.id;try{const result=await api('/orders/'+encodeURIComponent(order.id)+'/cancel',{});const index=store.orders.findIndex(o=>o.id===result.id);if(index>=0)store.orders.splice(index,1,result);await refreshStore().catch(()=>{});notify('تم إلغاء الحجز');}catch(e){await refreshStore().catch(()=>{});notify(e.message);}finally{cancellationBusy.value=null;}}
const supportBusy=ref(false);
async function support(){if(!session.value){requestAuth('login','support');return;}if(supportBusy.value)return;supportBusy.value=true;try{await createTicket(subject.value,message.value,session.value);notify('تم إرسال تذكرتك إلى لوحة الإدارة');subject.value='';message.value='';}catch(e){notify(e.message);}finally{supportBusy.value=false;}}
async function copy(order){if(connected){try{await copyOrderCodes(order,api,navigator.clipboard);notify('تم نسخ الكود');}catch(e){notify(e.message||'تعذّر النسخ');}return;}try{await navigator.clipboard.writeText('FATYA-NOT-REDEEMABLE');notify('تم نسخ الكود')}catch{notify("تعذّر النسخ الكود: FATYA-NOT-REDEEMABLE")}}
if(document.modelContext?.registerTool){try{document.modelContext.registerTool({name:'browse_cards',description:'Filter demo cards in the visible catalog; does not purchase.',inputSchema:{type:'object',properties:{query:{type:'string'}},required:['query'],additionalProperties:false},execute:async input=>{if(typeof input.query!=='string')throw Error('query must be a string');page.value='cards';query.value=input.query;await nextTick();return filtered.value.map(p=>({id:p.id,name:p.name}))}})}catch{}}
let syncTimer;
onMounted(async()=>{if(!connected)return;await refreshStore().catch(()=>{});try{const user=await api('/auth/me');session.value=user;entered.value=true;}catch{}syncTimer=setInterval(()=>{if(!document.hidden)refreshStore().catch(()=>{});},3000);});
onBeforeUnmount(()=>clearInterval(syncTimer));
return {available,denominationAvailable,deliveredOrder,cancellableOrder,orderStateLabel,paymentStateLabel,orderTime,cancelOrder,cancellationBusy,WebStore,StoreLayout,webMode,authVisible,authView,requestAuth,cancelAuth,productForSlide,asiacellProduct,asiacellDraft,backFromAsiacell,checkoutAsiacell,homeQuery,catalogSections,browseCards,supportPhoneHref,connected,connection,refreshStore,supportBusy,t,language,cardCount,profileError,saveProfile,marketing,store,admin,myTickets,entered,session,authRequest,enterStore,accountAccess,navMotion,navIcons,page,category,query,selected,quantity,denom,orders,toast,subject,message,success,dialog,products,number,filtered,price,filterCats,categoryIcons,categoryLabel,previewImage,gallery,shownImage,selectGalleryImage,nav,mobileNav,go,notify,open,close,startCheckout,paymentMethod,paymentDraft,paymentReceipt,confirmPayment,returnCheckout,support,copy};
},
template:`
<SplashIntro :enabled="!admin&&!webMode"/><div id="masal-content">
<PwaControls :allow-install="!marketing"/>
<MarketingSite v-if="marketing"/>
<Dashboard v-if="admin"/>
<div v-show="!admin&&!marketing&&(webMode?authVisible:!entered)" :class="{'web-auth-wrap':webMode}"><div v-if="webMode" class="web-auth-toolbar"><button class="web-auth-back" @click="cancelAuth"><ChevronLeft :size="18"/>{{t('الرجوع إلى المتجر')}}</button><StorePreferences/></div><AuthGate :request="authRequest" :initial-view="authView" @enter="enterStore"/></div>
<div v-if="(webMode||entered)&&!admin&&!marketing" v-show="!authVisible" :class="webMode?'web-store-shell':'app-shell'">
<PullToRefresh v-if="!webMode"/>
<component :is="webMode?WebStore:StoreLayout" :products="products" :categories="store.categories.filter(c=>c.active)" :session="session" :settings="store.settings" :page="page" :category="category" :query="query" :loading="connected&&!connection.ready" :error="connection.error" @browse="browseCards" @select="open" @navigate="go" @auth="requestAuth" @refresh="refreshStore">
<aside v-if="!webMode" class="sidebar"><a class="brand" href="#" @click.prevent="go('home')"><span class="brand-mark"><StoreBrandMark/></span><span>{{t(store.settings.name)}}<small>DIGITAL STORE</small></span></a><div class="side-label">{{t("المتجر")}}</div><nav><button v-for="n in nav" :class="{active:page===n.id}" :aria-current="page===n.id?'page':undefined" @click="go(n.id)"><NavIcon :icon="navIcons[n.icon]" :kind="n.id" :active="page===n.id" :motion="navMotion"/><span>{{t(n.label)}}</span><ChevronLeft/></button></nav><div class="side-bottom"><ShieldCheck/><p>{{t("فاتيا")}}</p></div></aside>
<main><StoreHeader v-if="!webMode" :show-theme="true" :name="store.settings.name" :order-count="orders.length" :ticket-count="myTickets.length"/>
<div class="content"><p v-if="connected&&connection.error" role="alert">{{connection.error}} <button @click="refreshStore">إعادة المحاولة</button></p><Transition name="view" mode="out-in"><div :key="page">
<template v-if="page==='home'">
<BannerSlider :resolve-product="productForSlide" @select="open"/><div class="hero"><div class="hero-copy"><span class="hero-label"><span></span> {{t("رصيد • ألعاب • بطاقات عالمية")}}</span><h2>{{t("بطاقات الرصيد")}}<br><span>{{t("والألعاب")}}</span></h2></div><CardDeck v-if="products.length" :products="products.slice(0,6)" @select="open"/></div>
<section class="store-discover" :aria-label="t('اكتشف البطاقات')">
<form class="search" role="search" @submit.prevent="browseCards('الكل',homeQuery)"><Search aria-hidden="true"/><input v-model="homeQuery" :placeholder="t('ابحث عن شركة أو بطاقة')" :aria-label="t('البحث عن بطاقة')"/><button type="submit" class="store-search-submit" :aria-label="t('بحث')"><ChevronLeft :size="20"/></button></form>
<div class="store-category-shortcuts store-home-shortcuts" :aria-label="t('تصنيفات البطاقات')"><button v-for="c in filterCats" :key="c" type="button" @click="browseCards(c)"><span class="store-category-icon"><component :is="categoryIcons[c]" :size="25" :stroke-width="1.6" aria-hidden="true"/></span><span>{{categoryLabel(c)}}</span></button><button type="button" @click="go('orders')"><span class="store-category-icon"><ShoppingBag :size="25" :stroke-width="1.6" aria-hidden="true"/></span><span>{{t('طلباتي')}}</span></button><button type="button" @click="go('support')"><span class="store-category-icon"><Headphones :size="25" :stroke-width="1.6" aria-hidden="true"/></span><span>{{t('الدعم')}}</span></button></div>
</section>
<section v-if="products.length" class="store-catalog-section store-featured-section" :aria-label="t('مختارات لك')"><div class="section-title"><h2>{{t('مختارات لك')}}</h2><button type="button" @click="browseCards()">{{t('عرض الكل')}} <ChevronLeft :size="16"/></button></div><StoreCards variant="featured" :products="products.slice(0,6)" :label="t('مختارات لك')" @select="open"/></section>
<template v-for="(section,index) in catalogSections" :key="section.id">
<section class="store-catalog-section store-brands-section" :aria-label="categoryLabel(section.name)"><div class="section-title"><div class="store-section-heading"><h2>{{categoryLabel(section.name)}}</h2></div><button type="button" @click="browseCards(section.name)" :aria-label="t('عرض الكل')+' · '+categoryLabel(section.name)">{{t('عرض الكل')}} <ChevronLeft :size="16"/></button></div><StoreCards variant="brands" :products="section.products.slice(0,6)" :label="categoryLabel(section.name)" @select="open"/></section>
<BannerSlider :resolve-product="productForSlide" @select="open" v-if="index===0" placement="middle" :interval="2000" :label="t('سلايدر وسطي')" :show-playback="false"/>
</template>
<div v-if="!catalogSections.length" class="empty"><Grid2X2/><h2>{{t('لا توجد بطاقات متاحة حالياً')}}</h2></div>
</template>
<template v-if="page==='cards'"><section class="store-browse"><div class="store-browse-heading"><h1>{{category==='الكل'?t('كل البطاقات'):categoryLabel(category)}}</h1><span>{{cardCount(filtered.length)}}</span></div><div class="search"><Search aria-hidden="true"/><input v-model="query" :placeholder="t('ابحث عن شركة أو بطاقة')" :aria-label="t('البحث عن بطاقة')"/></div><div class="store-category-shortcuts store-filter-tabs" :aria-label="t('تصنيفات البطاقات')"><button v-for="c in filterCats" :key="c" type="button" :aria-pressed="category===c" @click="category=c"><span class="store-category-icon"><component :is="categoryIcons[c]" :size="25" :stroke-width="1.7" aria-hidden="true"/></span><span>{{categoryLabel(c)}}</span></button></div><PagedList :items="filtered" :size="12" :reset-key="query+category+language" v-slot="{items:cardRows}"><StoreCards variant="brands" :products="cardRows" :label="t('البطاقات')" @select="open"/></PagedList><div class="empty" v-if="!filtered.length"><Search/><h2>{{t('ما لقينا بطاقة بهالاسم')}}</h2><button class="primary" @click="query='';category='الكل'">{{t('عرض كل البطاقات')}}</button></div></section></template>
<AsiacellFlow v-if="page==='asiacell'&&asiacellProduct" :product="asiacellProduct" :preview="!connected" :initial-draft="asiacellDraft" @back="backFromAsiacell" @checkout="checkoutAsiacell"/>
<template v-if="page==='orders'"><div v-if="!orders.length" class="empty"><ShoppingBag/><h2>{{t("أول بطاقة بانتظارك")}}</h2><p>{{t("طلباتك راح تظهر هنا بعد شراء بطاقة")}}</p><button class="primary" @click="go('cards')">{{t("اكتشف البطاقات")}}</button></div><PagedList :items="orders" v-slot="{items:orderRows}"><div class="order" v-for="o in orderRows" :key="o.id"><div><span class="status">{{t("طلب ·")}} {{t(connected?orderStateLabel(o):o.status)}}</span><h2>{{t(o.name)}} · {{o.offerLabel?t(o.offerLabel):number(o.value)}}</h2><small v-if="o.recipient" dir="ltr">{{o.recipient}}</small><small dir="ltr">{{o.id}} · {{o.date}}</small><small v-if="connected">{{t(paymentStateLabel(o))}}</small><small v-if="connected&&o.fulfillmentStatus==='reserved'">{{t("ينتهي الحجز")}}: {{orderTime(o.reservationExpiresAt)}}</small></div><b>{{number(o.price)}} {{t("د.ع")}}</b><button @click="copy(o)" :disabled="connected&&!deliveredOrder(o)" :title="connected&&!deliveredOrder(o)?t('الأكواد تتاح بعد تأكيد الدفع'):undefined"><Copy :size="18"/> {{t("نسخ كود")}}</button><button v-if="connected&&cancellableOrder(o)" @click="cancelOrder(o)" :disabled="!!cancellationBusy">{{t("إلغاء الحجز")}}</button></div></PagedList></template>
<template v-if="page==='support'"><div class="support-layout"><section v-if="supportPhoneHref" class="support-contact" :aria-label="t(&quot;الاتصال بالدعم الفني&quot;)"><span class="support-contact-icon"><Phone :size="26"/></span><div class="support-contact-copy"><h2>{{t("الاتصال بالدعم الفني")}}</h2><p dir="ltr">{{store.settings.supportPhone}}</p></div><a class="support-call primary" :href="supportPhoneHref" :aria-label="t(&quot;اتصل بالدعم على الرقم&quot;)+' '+store.settings.supportPhone"><Phone :size="18"/>{{t("اتصل الآن")}}</a></section><form class="support-form" @submit.prevent="support"><h2>{{t("شلون نكدر نساعدك؟")}}</h2><label>{{t("عنوان الرسالة")}}<input v-model="subject" required maxlength="100" :placeholder="t(&quot;اكتب موضوع استفسارك&quot;)"/></label><label>{{t("رسالتك")}}<textarea v-model="message" required maxlength="2000" rows="5" :placeholder="t(&quot;شلون نكدر نساعدك؟&quot;)"></textarea></label><button class="primary" :disabled="supportBusy">{{t("إرسال التذكرة")}}</button></form><div class="faq"><Headphones :size="36"/><h2>{{t("إجابات سريعة")}}</h2><p v-if="store.settings.supportEmail" dir="ltr">{{store.settings.supportEmail}}</p><details><summary>{{t("وين ألكى الكارت بعد الشراء؟")}}</summary><p>{{t("بقسم طلباتي تگدر تشوف تفاصيل الطلب وتنسخ الكود")}}</p></details><details><summary>{{t("شنو طرق الدفع؟")}}</summary><p>{{t("طرق الدفع المتوفرة: Qi وزين كاش")}}</p></details><details><summary>{{t("هل الأسعار نهائية؟")}}</summary><p>{{t("تظهر قيمة الطلب كاملة قبل تأكيد الشراء")}}</p></details></div></div><div class="ticket-history" v-if="myTickets.length"><h2>{{t("تذاكري")}}</h2><PagedList :items="myTickets" v-slot="{items:ticketRows}"><article v-for="ticket in ticketRows" :key="ticket.id"><span>{{ticket.id}} · {{t(ticket.status)}}</span><h3>{{ticket.subject}}</h3><p>{{ticket.message}}</p><p v-if="ticket.reply" class="ticket-reply">{{t("رد الإدارة:")}} {{ticket.reply}}</p></article></PagedList></div></template>
<template v-if="page==='payment'&&paymentDraft"><PaymentPreview :checkout="paymentDraft" :receipt="paymentReceipt" @confirm="confirmPayment" @back="returnCheckout" @orders="go('orders')"/></template>
<template v-if="page==='account'"><AccountPage :user="session" :error="profileError" :orders="orders" @save="saveProfile" @logout="accountAccess" @browse="go('cards')"/></template>
</div></Transition></div></main>
</component>
<MobileDock v-if="!webMode" :items="mobileNav" :icons="navIcons" :page="['payment','asiacell'].includes(page)?'cards':page" @navigate="go"/>
<dialog ref="dialog" class="card-purchase-dialog" @cancel="selected=null"><template v-if="selected"><button class="close" @click="close" :aria-label="t(&quot;إغلاق&quot;)"><X/></button><template v-if="!success"><h2>{{t(selected.name)}}</h2><p class="muted">{{t(selected.tag)}}</p><div class="dialog-art" v-if="shownImage"><img :src="shownImage" :alt="t(selected.name)"/></div><div class="dialog-gallery" v-if="gallery.length>1"><button v-for="g in gallery" :key="g" :class="{selected:shownImage===g}" @click="selectGalleryImage(g)" :aria-label="t(&quot;عرض صورة بطاقة &quot;)+t(selected.name)"><img :src="g" alt=""/></button></div><label class="field-label">{{t("فئة البطاقة")}}</label><div class="denominations"><button v-for="(v,i) in selected.values" :class="{selected:denom===i}" :aria-pressed="denom===i" @click="denom=i">{{number(v)}} <small>{{t(available(selected,i)?(selected.unit||"د.ع"):"نفد المخزون")}}</small></button></div><div class="quantity-row"><span>{{t("الكمية")}}</span><div class="stepper"><button :disabled="quantity<=1" @click="quantity--" :aria-label="t(&quot;تقليل الكمية&quot;)"><Minus :size="18"/></button><span>{{number(quantity)}}</span><button :disabled="quantity>=20" @click="quantity++" :aria-label="t(&quot;زيادة الكمية&quot;)"><Plus :size="18"/></button></div></div><PaymentMethods v-model="paymentMethod"/><div class="total"><span>{{t("المجموع")}}</span><strong>{{number(price)}} <small>{{t("د.ع")}}</small></strong></div><button class="primary wide" @click="startCheckout()" :disabled="!available(selected,denom)">{{t(available(selected,denom)?"إتمام الشراء":"نفد مخزون هذه الفئة")}}</button></template><template v-else><div class="success"><span><Check :size="36"/></span><h2>{{t("اكتمل الطلب!")}}</h2><p>{{t("أضفنا طلبك إلى طلباتي")}}</p><button class="primary wide" @click="close();go('orders')">{{t("عرض طلباتي")}}</button></div></template></template></dialog>
<Transition name="toast"><div v-if="toast" class="toast" role="status">{{t(toast)}}</div></Transition>
</div></div>`});app.component('StorePreferences',StorePreferences);app.component('MasalMark',MasalMark);app.component('ThemeToggle',ThemeToggle);app.use(router);app.mount('#app');
