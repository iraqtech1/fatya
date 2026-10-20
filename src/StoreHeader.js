import PagedList from './PagedList.js';
import {t,language} from './i18n.js';
import {ref,computed,watch,onMounted,onBeforeUnmount,useId,nextTick} from 'vue/dist/vue.esm-bundler.js';
import {Bell,X,CheckCheck,ChevronLeft,ArrowRight} from 'lucide-vue-next';
import StoreBrandMark from './StoreBrandMark.js';
import ThemeToggle from './ThemeToggle.js';
import './store-header.css';
export default {
 components:{PagedList,Bell,X,CheckCheck,ChevronLeft,ArrowRight,StoreBrandMark,ThemeToggle},
 props:{name:String,orderCount:Number,ticketCount:Number,showTheme:Boolean},
 setup(props){
  const root=ref(null),opened=ref(false),selectedMessage=ref(null),messageDetail=ref(null),panelId='notifications-'+useId();
  const messages=ref([
   {id:1,title:"أهلًا بك في فاتيا",body:"تصفّح بطاقات الرصيد والألعاب واختر الفئة التي تناسبك",time:'الآن',unread:true},
   {id:2,title:'بطاقاتك المفضّلة بانتظارك',body:"اكتشف البطاقات المتوفرة من قسم شراء بطاقة",time:'قبل دقيقة',unread:true},
   {id:3,title:'الدعم موجود لمساعدتك',body:"يمكنك إرسال استفسارك ومتابعة التذكرة من صفحة الدعم",time:'قبل دقيقتين',unread:true}
  ]);
  const unread=computed(()=>messages.value.filter(message=>message.unread).length);
  function add(title,body){messages.value.unshift({id:Date.now(),title,body,time:'الآن',unread:true});}
  watch(()=>props.orderCount,(value,old)=>{if(value>old)add('تم إضافة طلبك',"تفاصيل البطاقة متاحة في صفحة طلباتي");});
  watch(()=>props.ticketCount,(value,old)=>{if(value>old)add('وصلتنا تذكرتك',"يمكنك متابعة تفاصيلها من صفحة الدعم");});
  async function openMessage(message){message.unread=false;selectedMessage.value=message;await nextTick();messageDetail.value?.focus();}
  watch(opened,value=>{if(!value)selectedMessage.value=null;});
  function readAll(){messages.value.forEach(message=>message.unread=false);}
  function outside(event){if(root.value&&!event.composedPath().includes(root.value))opened.value=false;}
  onMounted(()=>document.addEventListener('click',outside));
  onBeforeUnmount(()=>document.removeEventListener('click',outside));
  return {t,language,root,opened,panelId,messages,unread,readAll,selectedMessage,messageDetail,openMessage};
 },
 template:`<header ref="root" class="store-header" @keydown.esc="opened=false">
  <div class="store-header-brand"><StoreBrandMark/><div><strong>{{t(name)}}</strong><span>{{t("بطاقات الرصيد والألعاب")}}</span></div></div>
  <div class="store-header-actions"><ThemeToggle v-if="showTheme"/><button type="button" class="notification-bell" :class="{'has-unread':unread}" :aria-label="unread?t(&quot;الإشعارات — &quot;)+unread+t(&quot; غير مقروءة&quot;):t(&quot;الإشعارات&quot;)" :aria-expanded="opened" :aria-controls="panelId" @click="opened=!opened"><Bell :size="25"/><span v-if="unread" class="notification-count" aria-hidden="true">{{unread>99?'99+':unread}}</span></button></div>
  <section v-if="opened" :id="panelId" class="notification-panel" :aria-label="t(&quot;قائمة الإشعارات&quot;)">
   <div class="notification-heading"><h2>{{selectedMessage?t("تفاصيل الإشعار"):t("الإشعارات")}}</h2><button type="button" class="notification-close" :aria-label="t(&quot;إغلاق الإشعارات&quot;)" @click="opened=false"><X :size="19"/></button></div>
   <template v-if="!selectedMessage">
   <button v-if="unread" type="button" class="notification-read-all notification-framed" @click="readAll"><CheckCheck :size="16"/>{{t("تحديد الكل كمقروء")}}</button>
   <PagedList :items="messages" :size="5" :scroll="false" v-slot="{items:notificationRows}"><ul><li v-for="message in notificationRows" :key="message.id" :class="{unread:message.unread}"><button type="button" class="notification-item" @click.stop="openMessage(message)"><span v-if="message.unread" class="notification-dot" :aria-label="t(&quot;غير مقروء&quot;)"></span><div><h3>{{t(message.title)}}</h3><p>{{t(message.body)}}</p><small>{{t(message.time)}}</small></div><span class="notification-item-arrow"><ChevronLeft :size="16"/></span></button></li></ul></PagedList></template>
   <template v-else><button type="button" class="notification-back notification-framed" @click="selectedMessage=null"><ArrowRight :size="16"/>{{t("العودة إلى الإشعارات")}}</button><article ref="messageDetail" class="notification-detail" tabindex="-1"><h3>{{t(selectedMessage.title)}}</h3><p>{{t(selectedMessage.body)}}</p><small>{{t(selectedMessage.time)}}</small></article></template>
  </section>
 </header>`
};
