import {useViewHistory} from './view-history.js';
import {store} from './store.js';
import ThemeScene from './ThemeScene.js';
import {t,language} from './i18n.js';
import {ref,watch,nextTick} from 'vue/dist/vue.esm-bundler.js';
import {Archive,UserRoundPen,ShieldCheck,Info,LogOut,Moon,Languages,ChevronDown,ChevronLeft,ArrowRight,User,Trash2,Check,X} from 'lucide-vue-next';
import {storeDark as dark} from './ThemeToggle.js';
import './account.css';
import DeveloperAbout from './DeveloperAbout.js';
import OrderArchive from './OrderArchive.js';
import PushSettings from './PushSettings.js';
import {defineAsyncComponent} from 'vue/dist/vue.esm-bundler.js';
const RabiaaActivation=defineAsyncComponent(()=>import('./RabiaaActivation.js'));
export default {
  components:{RabiaaActivation,Trash2,Check,X,PushSettings,OrderArchive,Archive,DeveloperAbout,ThemeScene,UserRoundPen,ShieldCheck,Info,LogOut,Moon,Languages,ChevronDown,ChevronLeft,ArrowRight,User},
  props:{user:Object,error:String,orders:{type:Array,default:()=>[]}},emits:['save','logout','browse'],
  setup(props,{emit}){
    const view=ref('menu'),name=ref(''),email=ref(''),popup=ref(''),accountDialog=ref(null);
    const languages=[{id:'ar',label:'العربية'},{id:'ku',label:'کوردی'},{id:'en',label:'English'}];
    watch(popup,async value=>{await nextTick();if(value){if(!accountDialog.value.open)accountDialog.value.showModal();}else accountDialog.value?.close();});
    function chooseLanguage(id){language.value=id;popup.value='';}
    function confirmExit(){popup.value='';emit('logout');}
    useViewHistory('account',()=>({view:view.value}),state=>{if(state.view==='edit'&&view.value!=='edit'){edit();return;}view.value=['menu','edit','archive','privacy','about','activation'].includes(state.view)?state.view:'menu';});

    function edit(){name.value=props.user.previewId&&props.user.name==='حساب تجريبي'?'':props.user.name;email.value=props.user.email;view.value='edit';}
    function save(){emit('save',{name:name.value,email:email.value,done:()=>{view.value='menu';}});}
    return {store,t,language,dark,view,name,email,edit,save,popup,accountDialog,languages,chooseLanguage,confirmExit};
  },
  template:`<section class="account-page" :aria-label="t(&quot;حسابي&quot;)">
  <template v-if="view==='menu'"><div class="account-identity"><span><User :size="28"/></span><div><h2 v-if="user.name&&!(user.previewId&&user.name==='حساب تجريبي')">{{t(user.name)}}</h2><p v-if="user.phone" dir="ltr">{{user.phone}}</p></div></div>
  <div class="account-menu">
  <PushSettings/>
  <button class="account-row" @click="view='activation'"><span class="account-row-icon"><ShieldCheck/></span><span>{{t('تفعيل بطاقة الرابعة')}}</span><ChevronLeft class="account-chevron"/></button>
  <button class="account-row" @click="edit"><span class="account-row-icon"><UserRoundPen/></span><span>{{t("الملف الشخصي")}}</span><ChevronLeft class="account-chevron"/></button>
  <button class="account-row" @click="view='archive'"><span class="account-row-icon"><Archive/></span><span>{{t('ارشيف الطلبات')}}</span><ChevronLeft class="account-chevron"/></button>
  <button class="account-row" @click="view='privacy'"><span class="account-row-icon"><ShieldCheck/></span><span>{{t("الخصوصية والشروط")}}</span><ChevronLeft class="account-chevron"/></button>
  <button class="account-row" @click="view='about'"><span class="account-row-icon"><Info/></span><span>{{t("حول التطبيق")}}</span><ChevronLeft class="account-chevron"/></button>
  <button type="button" class="account-row account-language" aria-haspopup="dialog" @click="popup='language'"><span class="account-row-icon"><Languages/></span><span>{{t('اللغة')}}</span><span class="account-language-value">{{languages.find(l=>l.id===language).label}}<ChevronDown :size="16"/></span></button>
  <button class="account-row account-theme-row" role="switch" :aria-checked="dark" @click="dark=!dark"><span class="account-row-icon"><Moon/></span><span>{{dark?t("الوضع الليلي"):t("الوضع النهاري")}}</span><ThemeScene class="account-theme-scene" :dark="dark"/></button>
  <button class="account-row" @click="popup='logout'"><span class="account-row-icon"><LogOut/></span><span>{{t("تسجيل الخروج")}}</span><ChevronLeft class="account-chevron"/></button>
  <button class="account-row account-delete-row" @click="popup='delete'"><span class="account-row-icon"><Trash2/></span><span>{{t('حذف الحساب')}}</span><ChevronLeft class="account-chevron"/></button>
  </div></template>
  <template v-else><button class="account-back" :class="{'archive-back':view==='archive'}" @click="view='menu'"><ArrowRight :size="18"/> {{t("العودة إلى حسابي")}}</button>
  <OrderArchive v-if="view==='archive'" :orders="orders" @browse="$emit('browse')"/>
  <RabiaaActivation v-if="view==='activation'"/>
  <form v-if="view==='edit'" class="account-panel" @submit.prevent="save"><h2>{{t("الملف الشخصي")}}</h2><label>{{t("الاسم الكامل")}}<input v-model="name" required minlength="2" maxlength="80" autocomplete="name"/></label><label>{{t("رقم الهاتف")}}<input :value="user.phone" :placeholder="t(&quot;غير مضاف&quot;)" readonly dir="ltr"/></label><label>{{t("البريد الإلكتروني")}} <small>{{t("(اختياري)")}}</small><input v-model="email" type="email" maxlength="254" autocomplete="email" dir="ltr"/></label><p v-if="error" class="auth-error" role="alert">{{t(error)}}</p><button class="primary wide">{{t("حفظ التعديلات")}}</button></form>
  <article v-if="view==='privacy'" class="account-panel account-information"><h2>{{t("الخصوصية والشروط")}}</h2><p v-if="store.settings.privacyText" class="account-custom-text" dir="auto">{{store.settings.privacyText}}</p><template v-else><p>{{t("بيانات حسابك تُستخدم لعرض ملفك وطلباتك وتذاكر الدعم داخل التطبيق")}}</p></template></article>
  <article v-if="view==='about'" class="account-panel account-information"><h2>{{t("حول التطبيق")}}</h2><p v-if="store.settings.aboutText" class="account-custom-text" dir="auto">{{store.settings.aboutText}}</p><template v-else><p>{{t("فاتيا — بطاقات الرصيد والألعاب والاشتراكات الرقمية في مكان واحد")}}</p><p>{{t("تصفّح البطاقات تابع مشترياتك وتواصل مع الدعم من داخل التطبيق")}}</p></template><DeveloperAbout/></article></template>
  <dialog ref="accountDialog" class="account-dialog" aria-labelledby="account-dialog-title" @cancel.prevent="popup=''" @close="popup=''" @click="$event.target===accountDialog&&(popup='')">
   <template v-if="popup"><button type="button" class="account-dialog-close" :aria-label="t('إغلاق')" @click="popup=''"><X :size="20"/></button><span class="account-dialog-icon"><Languages v-if="popup==='language'"/><Trash2 v-else-if="popup==='delete'"/><LogOut v-else/></span>
   <h2 id="account-dialog-title">{{t(popup==='language'?'لغة التطبيق':popup==='delete'?'حذف الحساب':'تسجيل الخروج')}}</h2>
   <div v-if="popup==='language'" class="account-language-options" role="group" :aria-label="t('لغة التطبيق')"><button v-for="lang in languages" :key="lang.id" type="button" :aria-pressed="language===lang.id" :lang="lang.id==='ku'?'ckb':lang.id" @click="chooseLanguage(lang.id)"><span>{{lang.label}}</span><Check v-if="language===lang.id" :size="20"/></button></div>
   <template v-else><p>{{t(popup==='delete'?'سيتم حذف الحساب خلال مدة 24 ساعة، وسيتم تسجيل خروجك من التطبيق.':'هل تريد تسجيل الخروج من التطبيق؟')}}</p><div class="account-dialog-actions"><button type="button" class="account-dialog-secondary" autofocus @click="popup=''">{{t('إلغاء')}}</button><button type="button" class="primary" @click="confirmExit">{{t(popup==='delete'?'تأكيد والخروج':'تسجيل الخروج')}}</button></div></template></template>
  </dialog></section>`
};
