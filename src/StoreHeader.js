import {useViewHistory} from './view-history.js';
import PagedList from './PagedList.js';
import {t,language} from './i18n.js';
import {api,connected} from './api.js';
import {store,connection,readNotifications} from './store.js';
import {ref,computed,watch,onMounted,onBeforeUnmount,useId,nextTick} from 'vue/dist/vue.esm-bundler.js';
import {Bell,X,CheckCheck,ChevronLeft,ArrowRight} from 'lucide-vue-next';
import StoreBrandMark from './StoreBrandMark.js';
import ThemeToggle from './ThemeToggle.js';
import './store-header.css';
import {notificationId,notificationTarget} from './notification-links.js';
export default {
 components:{PagedList,Bell,X,CheckCheck,ChevronLeft,ArrowRight,StoreBrandMark,ThemeToggle},
 props:{name:String,orderCount:Number,ticketCount:Number,showTheme:Boolean},
 setup(props){
  const root=ref(null),opened=ref(false),selectedMessage=ref(null),messageDetail=ref(null),panelId='notifications-'+useId();
  const previewMessages=ref([
   {id:1,title:"أهلًا بك في فاتيا",body:"تصفّح بطاقات الرصيد والألعاب واختر الفئة التي تناسبك",time:'الآن',unread:true},
   {id:2,title:'بطاقاتك المفضّلة بانتظارك',body:"اكتشف البطاقات المتوفرة من قسم شراء بطاقة",time:'قبل دقيقة',unread:true},
   {id:3,title:'الدعم موجود لمساعدتك',body:"يمكنك إرسال استفسارك ومتابعة التذكرة من صفحة الدعم",time:'قبل دقيقتين',unread:true}
  ]);
  const messages=computed(()=>connected?store.notifications:previewMessages.value);
  useViewHistory('notifications',()=>({open:opened.value,message:selectedMessage.value?.id||''}),async state=>{
    opened.value=!!state.open;await nextTick();selectedMessage.value=opened.value?messages.value.find(m=>String(m.id)===String(state.message))||null:null;
  });
  const unread=computed(()=>messages.value.filter(message=>message.unread).length);
  function add(title,body){messages.value.unshift({id:Date.now(),title,body,time:'الآن',unread:true});}
  watch(()=>props.orderCount,(value,old)=>{if(!connected&&value>old)add('تم إضافة طلبك',"تفاصيل البطاقة متاحة في صفحة طلباتي");});
  watch(()=>props.ticketCount,(value,old)=>{if(!connected&&value>old)add('وصلتنا تذكرتك',"يمكنك متابعة تفاصيلها من صفحة الدعم");});
  async function openMessage(message){const owner=connection.customer?.id;try{if(connected)await readNotifications([message.id]);else message.unread=false;}catch(e){connection.error=e.message;}if(connected&&owner!==connection.customer?.id)return;selectedMessage.value=message;await nextTick();messageDetail.value?.focus();}
  let requested=notificationId(),loadingRequest=false;
  async function openRequested(){if(!requested||loadingRequest||connected&&!connection.customer)return;const id=requested,owner=connection.customer?.id;loadingRequest=true;try{const row=messages.value.find(message=>message.id===id)||(connected?(await api('/notifications/'+encodeURIComponent(id))).notification:null);if(requested!==id||connected&&connection.customer?.id!==owner)return;requested='';if(row){opened.value=true;await openMessage(row);}}catch(e){if(requested===id){requested='';connection.error=e.message;}}finally{loadingRequest=false;}}
  watch(()=>[messages.value,connection.customer?.id],openRequested,{deep:true,immediate:true});
  watch(()=>connection.customer?.id,(_next,previous)=>{if(previous){requested='';selectedMessage.value=null;opened.value=false;}});
  function pushClick(event){if(event.data?.type!=='takeed:click')return;const id=event.data.payload?.notification_id;if(!/^[0-9a-f-]{36}$/i.test(id||''))return;requested=id;openRequested();}
  function followMessage(){const path=notificationTarget(selectedMessage.value?.targetPath);if(path){opened.value=false;window.dispatchEvent(new CustomEvent('fatya:notification-target',{detail:path}));}}
  watch(opened,value=>{if(!value)selectedMessage.value=null;});
  async function readAll(){try{if(connected)await readNotifications(messages.value.filter(m=>m.unread).map(m=>m.id));else messages.value.forEach(message=>message.unread=false);}catch(e){connection.error=e.message;}}
  function outside(event){if(root.value&&!event.composedPath().includes(root.value))opened.value=false;}
  onMounted(()=>{document.addEventListener('click',outside);navigator.serviceWorker?.addEventListener('message',pushClick);});
  onBeforeUnmount(()=>{document.removeEventListener('click',outside);navigator.serviceWorker?.removeEventListener('message',pushClick);});
  return {notificationTarget,followMessage,t,language,root,opened,panelId,messages,unread,readAll,selectedMessage,messageDetail,openMessage};
 },
 template:`<header ref="root" class="store-header" @keydown.esc="opened=false">
  <div class="store-header-brand"><StoreBrandMark/><div><strong>{{t(name)}}</strong><span>{{t("بطاقات الرصيد والألعاب")}}</span></div></div>
  <div class="store-header-actions"><ThemeToggle v-if="showTheme"/><button type="button" class="notification-bell" :class="{'has-unread':unread}" :aria-label="unread?t(&quot;الإشعارات — &quot;)+unread+t(&quot; غير مقروءة&quot;):t(&quot;الإشعارات&quot;)" :aria-expanded="opened" :aria-controls="panelId" @click="opened=!opened"><Bell :size="25"/><span v-if="unread" class="notification-count" aria-hidden="true">{{unread>99?'99+':unread}}</span></button></div>
  <section v-if="opened" :id="panelId" class="notification-panel" :aria-label="t(&quot;قائمة الإشعارات&quot;)">
   <div class="notification-heading"><h2>{{selectedMessage?t("تفاصيل الإشعار"):t("الإشعارات")}}</h2><button type="button" class="notification-close" :aria-label="t(&quot;إغلاق الإشعارات&quot;)" @click="opened=false"><X :size="19"/></button></div>
   <template v-if="!selectedMessage">
   <button v-if="unread" type="button" class="notification-read-all notification-framed" @click="readAll"><CheckCheck :size="16"/>{{t("تحديد الكل كمقروء")}}</button>
   <PagedList history-key="storeheader-1" :items="messages" :size="5" :scroll="false" v-slot="{items:notificationRows}"><ul><li v-for="message in notificationRows" :key="message.id" :class="{unread:message.unread}"><button type="button" class="notification-item" @click.stop="openMessage(message)"><span v-if="message.unread" class="notification-dot" :aria-label="t(&quot;غير مقروء&quot;)"></span><div><h3>{{t(message.title)}}</h3><p>{{t(message.body)}}</p><small>{{t(message.time)}}</small></div><span class="notification-item-arrow"><ChevronLeft :size="16"/></span></button></li></ul></PagedList></template>
   <template v-else><button type="button" class="notification-back notification-framed" @click="selectedMessage=null"><ArrowRight :size="16"/>{{t("العودة إلى الإشعارات")}}</button><article ref="messageDetail" class="notification-detail" tabindex="-1"><h3>{{t(selectedMessage.title)}}</h3><p>{{t(selectedMessage.body)}}</p><img v-if="selectedMessage.image" :src="selectedMessage.image" :alt="selectedMessage.title" class="notification-detail-image"/><button v-if="notificationTarget(selectedMessage.targetPath)" class="notification-framed" @click="followMessage">{{t('فتح الصفحة المرتبطة')}}</button><small>{{t(selectedMessage.time)}}</small></article></template>
  </section>
 </header>`
};
