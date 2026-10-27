import {store} from './store.js';
import ThemeScene from './ThemeScene.js';
import {t,language} from './i18n.js';
import {ref} from 'vue/dist/vue.esm-bundler.js';
import {Archive,UserRoundPen,ShieldCheck,Info,LogOut,Moon,Languages,ChevronDown,ChevronLeft,ArrowRight,User} from 'lucide-vue-next';
import {storeDark as dark} from './ThemeToggle.js';
import './account.css';
import DeveloperAbout from './DeveloperAbout.js';
import OrderArchive from './OrderArchive.js';
import PushSettings from './PushSettings.js';
export default {
  components:{PushSettings,OrderArchive,Archive,DeveloperAbout,ThemeScene,UserRoundPen,ShieldCheck,Info,LogOut,Moon,Languages,ChevronDown,ChevronLeft,ArrowRight,User},
  props:{user:Object,error:String,orders:{type:Array,default:()=>[]}},emits:['save','logout','browse'],
  setup(props,{emit}){
    const view=ref('menu'),name=ref(''),email=ref('');
    function edit(){name.value=props.user.previewId&&props.user.name==='حساب تجريبي'?'':props.user.name;email.value=props.user.email;view.value='edit';}
    function save(){emit('save',{name:name.value,email:email.value,done:()=>{view.value='menu';}});}
    return {store,t,language,dark,view,name,email,edit,save};
  },
  template:`<section class="account-page" :aria-label="t(&quot;حسابي&quot;)">
  <template v-if="view==='menu'"><div class="account-identity"><span><User :size="28"/></span><div><h2 v-if="user.name&&!(user.previewId&&user.name==='حساب تجريبي')">{{t(user.name)}}</h2><p v-if="user.phone" dir="ltr">{{user.phone}}</p></div></div>
  <div class="account-menu">
  <PushSettings/>
  <button class="account-row" @click="edit"><span class="account-row-icon"><UserRoundPen/></span><span>{{t("الملف الشخصي")}}</span><ChevronLeft class="account-chevron"/></button>
  <button class="account-row" @click="view='archive'"><span class="account-row-icon"><Archive/></span><span>{{t('ارشيف الطلبات')}}</span><ChevronLeft class="account-chevron"/></button>
  <button class="account-row" @click="view='privacy'"><span class="account-row-icon"><ShieldCheck/></span><span>{{t("الخصوصية والشروط")}}</span><ChevronLeft class="account-chevron"/></button>
  <button class="account-row" @click="view='about'"><span class="account-row-icon"><Info/></span><span>{{t("حول التطبيق")}}</span><ChevronLeft class="account-chevron"/></button>
  <label class="account-row account-language"><span class="account-row-icon"><Languages/></span><span>{{t('اللغة')}}</span><span class="account-language-picker"><select v-model="language" :aria-label="t('لغة التطبيق')"><option value="ar" lang="ar">العربية</option><option value="ku" lang="ckb">کوردی</option><option value="en" lang="en">English</option></select><ChevronDown :size="16" aria-hidden="true"/></span></label>
  <button class="account-row account-theme-row" role="switch" :aria-checked="dark" @click="dark=!dark"><span class="account-row-icon"><Moon/></span><span>{{dark?t("الوضع الليلي"):t("الوضع النهاري")}}</span><ThemeScene class="account-theme-scene" :dark="dark"/></button>
  <button class="account-row" @click="$emit('logout')"><span class="account-row-icon"><LogOut/></span><span>{{t("تسجيل الخروج")}}</span><ChevronLeft class="account-chevron"/></button>
  </div></template>
  <template v-else><button class="account-back" :class="{'archive-back':view==='archive'}" @click="view='menu'"><ArrowRight :size="18"/> {{t("العودة إلى حسابي")}}</button>
  <OrderArchive v-if="view==='archive'" :orders="orders" @browse="$emit('browse')"/>
  <form v-if="view==='edit'" class="account-panel" @submit.prevent="save"><h2>{{t("الملف الشخصي")}}</h2><label>{{t("الاسم الكامل")}}<input v-model="name" required minlength="2" maxlength="80" autocomplete="name"/></label><label>{{t("رقم الهاتف")}}<input :value="user.phone" :placeholder="t(&quot;غير مضاف&quot;)" readonly dir="ltr"/></label><p class="muted">{{user.previewId?t("رقم الهاتف غير مضاف إلى حسابك"):t("رقم الهاتف مرتبط بتوثيق حسابك")}}</p><label>{{t("البريد الإلكتروني")}} <small>{{t("(اختياري)")}}</small><input v-model="email" type="email" maxlength="254" autocomplete="email" dir="ltr"/></label><p v-if="error" class="auth-error" role="alert">{{t(error)}}</p><button class="primary wide">{{t("حفظ التعديلات")}}</button></form>
  <article v-if="view==='privacy'" class="account-panel account-information"><h2>{{t("الخصوصية والشروط")}}</h2><p v-if="store.settings.privacyText" class="account-custom-text" dir="auto">{{store.settings.privacyText}}</p><template v-else><p>{{t("بيانات حسابك تُستخدم لعرض ملفك وطلباتك وتذاكر الدعم داخل التطبيق")}}</p><p>{{t("تُحفظ جلسة الدخول في هذا التبويب وتُحفظ تفضيلات المظهر على جهازك تسجيل الخروج يمسح جلسة الدخول")}}</p></template></article>
  <article v-if="view==='about'" class="account-panel account-information"><h2>{{t("حول التطبيق")}}</h2><p v-if="store.settings.aboutText" class="account-custom-text" dir="auto">{{store.settings.aboutText}}</p><template v-else><p>{{t("فاتيا — بطاقات الرصيد والألعاب والاشتراكات الرقمية في مكان واحد")}}</p><p>{{t("تصفّح البطاقات تابع مشترياتك وتواصل مع الدعم من داخل التطبيق")}}</p></template><DeveloperAbout/></article></template>
  </section>`
};
