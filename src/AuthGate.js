import {api,connected} from './api.js';
import {createRemoteAuth} from './remote-auth.js';
import {t,language} from './i18n.js';
import {store} from './store.js';
import {ref,computed,watch,nextTick,onMounted,onBeforeUnmount} from 'vue/dist/vue.esm-bundler.js';
import {ChevronRight,User,Smartphone,Mail,ShieldCheck,ArrowLeft,LockKeyhole,Eye,EyeOff} from 'lucide-vue-next';
import StoreBrandMark from './StoreBrandMark.js';
import {createPreviewAuth,createPreviewUser,normalizePhone} from './auth-preview.js';
import './auth.css';
import {withPreviewAccount} from './preview-account.js';

export const auth=connected?createRemoteAuth():withPreviewAccount(createPreviewAuth());
export default {
  components:{ChevronRight,User,Smartphone,Mail,ShieldCheck,ArrowLeft,LockKeyhole,Eye,EyeOff,StoreBrandMark},
  props:{request:Number,initialView:{type:String,default:'login'}},
  emits:['enter'],
  setup(props,{emit}){
    const view=ref(props.initialView==='register'?'register':'login'),name=ref(''),phone=ref(''),password=ref(''),visible=ref(false),email=ref(''),code=ref(''),notice=ref(''),error=ref(''),busy=ref(false),heading=ref(null),otpInput=ref(null),challenge=ref(null),now=ref(Date.now());
    let timer;
    const remaining=computed(()=>Math.max(0,Math.ceil(((challenge.value?.resendAt||0)-now.value)/1000)));
    const expired=computed(()=>!!challenge.value&&now.value>=challenge.value.expiresAt);
    const displayPhone=computed(()=>normalizePhone(phone.value));
    const digits=value=>value.replace(/[٠-٩]/g,c=>String(c.charCodeAt(0)-1632)).replace(/[۰-۹]/g,c=>String(c.charCodeAt(0)-1776));
    function cleanPhone(event){const value=digits(event.target.value).replace(/\D/g,'').slice(0,11);phone.value=value;event.target.value=value;}
    function cleanCode(value){code.value=digits(value).replace(/\D/g,'').slice(0,6);}
    async function focus(){await nextTick();(view.value==='otp'?otpInput.value:heading.value)?.focus();window.scrollTo({top:0,behavior:'instant'});}
    async function change(next){auth.cancel();challenge.value=null;view.value=next;password.value='';visible.value=false;error.value='';notice.value='';code.value='';await focus();}
    watch(()=>props.request,()=>{name.value='';phone.value='';email.value='';change(props.initialView==='register'?'register':'login');});
    onMounted(()=>{timer=setInterval(()=>now.value=Date.now(),1000);});
    onBeforeUnmount(()=>{clearInterval(timer);auth.cancel();});
    function allowed(mobile){const customer=store.customers.find(c=>normalizePhone(c.phone)===mobile);if(customer&&!customer.active)throw Error("هذا الحساب موقوف راجع إدارة المتجر");}
    async function submit(){
      if(busy.value)return;
      busy.value=true;error.value='';
      try{
        allowed(displayPhone.value);
        if(view.value==='reset'){
          challenge.value=await auth.start({mode:'reset',phone:phone.value});now.value=Date.now();code.value='';password.value='';view.value='otp';await focus();return;
        }
        if(view.value==='otp'){
          if(challenge.value?.mode==='register'){
            const user=await auth.verify(code.value);password.value='';emit('enter',user);return;
          }
          await auth.resetPassword(code.value,password.value);await change('login');notice.value="تم تغيير كلمة المرور سجّل الدخول بكلمة المرور الجديدةة";return;
        }
        const data={phone:phone.value,password:password.value,name:name.value,email:email.value};
        const user=await (view.value==='register'?auth.register(data):auth.login(data));
        if(user?.challengeId){challenge.value=user;now.value=Date.now();code.value='';password.value='';view.value='otp';await focus();return;}
        password.value='';emit('enter',user);
      }catch(e){error.value=e.message;}finally{busy.value=false;}
    }

    async function resend(){
      if(busy.value||remaining.value)return;
      busy.value=true;error.value='';
      try{allowed(displayPhone.value);challenge.value=await auth.resend();now.value=Date.now();code.value='';await focus();}catch(e){error.value=e.message;}finally{busy.value=false;}
    }
    function back(){change(challenge.value?.mode==='register'?'register':'reset');}
    return {t,language,store,view,name,phone,password,visible,email,code,notice,error,busy,heading,otpInput,challenge,remaining,expired,displayPhone,change,submit,resend,back,cleanCode,cleanPhone};
  },
  template:`<div class="auth-shell phone-auth" :class="{'auth-login':view==='login','auth-register':view==='register','auth-reset':view==='reset'}">
    <aside class="auth-story" aria-hidden="true"><span class="auth-wordmark">FATYA / DIGITAL STORE</span><div><span class="auth-kicker">{{t("بطاقاتك بمكان واحد")}}</span><h2>{{t("أهلًا بك")}}<br>{{t("بفاتيا")}}</h2><p>{{t("رصيد واتصالات ألعاب ومتاجر عالمية")}}<br>{{t("كل اللي تحتاجه صار أقرب إلك")}}</p></div><div class="auth-art"><span>Zain<small>{{t("رصيد واتصالات")}}</small></span><span>PUBG<small>{{t("عالم الألعاب")}}</small></span><span>Apple<small>{{t("بطاقات عالمية")}}</small></span></div></aside>
    <section class="auth-panel" :aria-label="t(&quot;الدخول إلى فاتيا&quot;)">
      <div class="auth-top"><span class="auth-step">{{view==='otp'?t("تأكيد رقم الهاتف"):view==='reset'?t("استرجاع كلمة المرور"):view==='register'?t("حساب جديد"):t("أهلًا بك")}}</span><button v-if="view!=='login'" type="button" class="auth-back" @click="view==='otp'?back():change('login')" :disabled="busy" :aria-label="t(&quot;الرجوع&quot;)"><ChevronRight :size="22"/></button></div>
      <div class="auth-body">
        <div class="auth-identity"><div class="auth-logo"><StoreBrandMark full/></div><strong>{{t(store.settings.name)}}</strong><span dir="ltr">DIGITAL STORE</span></div>
        <h1 ref="heading" tabindex="-1">{{view==='otp'?t("توثيق رقم الهاتف"):view==='reset'?t("استرجاع كلمة المرور"):view==='register'?t("إنشاء حساب"):t("تسجيل الدخول")}}</h1>
        <p class="auth-intro" v-if="view==='login'">{{t("أدخل رقم هاتفك وكلمة المرور لتسجيل الدخول إلى حسابك")}}</p>
        <p class="auth-intro" v-else-if="view==='register'">{{t("أدخل معلوماتك واختر كلمة مرور لحسابك")}}</p>
        <p class="auth-intro" v-else-if="view==='reset'">{{t("أدخل رقم هاتف حسابك لاستعادة كلمة المرور")}}</p>
        <p class="auth-intro" v-else>{{t("أكّد رقمك برمز التحقق المكوّن من 6 أرقام")}}<br><b dir="ltr">{{displayPhone}}</b> <button class="auth-edit" type="button" @click="back" :disabled="busy">{{t("تغيير الرقم")}}</button></p>
        <form class="auth-form" @submit.prevent="submit" :aria-busy="busy">
          <template v-if="view==='register'"><label for="auth-name">{{t("الاسم الكامل")}}</label><div class="auth-field"><User :size="20"/><input id="auth-name" v-model="name" autocomplete="name" :placeholder="t(&quot;اسمك الكامل&quot;)" required maxlength="80"/></div></template>
          <template v-if="view!=='otp'"><label for="auth-phone">{{t("رقم الهاتف / واتساب")}}</label><div class="auth-field"><Smartphone :size="20"/><input id="auth-phone" :value="phone" @input="cleanPhone" type="tel" inputmode="numeric" autocomplete="tel-national" dir="ltr" placeholder="07XXXXXXXXX" required minlength="11" maxlength="11" pattern="07[3-9][0-9]{8}" :title="t(&quot;رقم هاتف عراقي من 11 رقماً يبدأ بـ07&quot;)" aria-describedby="auth-phone-help"/></div><small id="auth-phone-help" class="auth-help">{{t("استخدم رقمك العراقي المرتبط بواتساب من 11 رقماً يبدأ بـ07")}}</small></template>
          <template v-if="view==='otp'"><label for="auth-otp">{{t("رمز التحقق")}}</label><div class="auth-field auth-otp-field"><ShieldCheck :size="21"/><input id="auth-otp" ref="otpInput" :value="code" @input="cleanCode($event.target.value)" type="text" inputmode="numeric" autocomplete="one-time-code" dir="ltr" placeholder="000000" required minlength="6" maxlength="6" pattern="[0-9]{6}" aria-describedby="auth-otp-help"/></div><small id="auth-otp-help" class="auth-help">{{expired?t("انتهت صلاحية الرمز اطلب رمزاً جديداً"):t("الرمز صالح لمدة 5 دقائق")}}</small></template>
          <template v-if="view!=='reset'&&(view!=='otp'||challenge?.mode!=='register')"><label for="auth-password">{{view==='otp'?t("كلمة المرور الجديدة"):t("كلمة المرور")}}</label><div class="auth-field"><LockKeyhole :size="20"/><input id="auth-password" v-model="password" :type="visible?'text':'password'" :autocomplete="view==='login'?'current-password':'new-password'" dir="ltr" required minlength="8" maxlength="128" :placeholder="t('كلمة المرور')"/><button type="button" @click="visible=!visible" :aria-label="t(visible?'إخفاء كلمة المرور':'إظهار كلمة المرور')" :aria-pressed="visible"><EyeOff v-if="visible" :size="20"/><Eye v-else :size="20"/></button></div><small class="auth-help">{{t("كلمة المرور يجب أن تكون من 8 أرقام أو حروف فما فوق")}}</small></template>
          <p v-if="notice" class="auth-help" role="status">{{t(notice)}}</p>
          <button v-if="view==='login'" type="button" class="auth-edit auth-recovery" @click="change('reset')" :disabled="busy">{{t("استرجاع كلمة المرور")}}</button>
          <p v-if="error" class="auth-error" role="alert">{{t(error)}}</p>
          <button class="primary auth-action" :disabled="busy||(view==='otp'&&(code.length!==6||expired))">{{busy?t("لحظة"):view==='otp'?t(challenge?.mode==='register'?"تأكيد رقم الهاتف":"حفظ كلمة المرور الجديدة"):view==='reset'?t("إرسال رمز التحقق"):view==='reset'?t("استرجاع كلمة المرور"):view==='register'?t("إنشاء حساب"):t("تسجيل الدخول")}}<ArrowLeft :size="19"/></button>
        </form>
        <div v-if="view==='otp'" class="auth-verification"><p>{{t("لم يصلك الرمز؟")}} <button type="button" @click="resend" :disabled="busy||remaining>0">{{remaining>0?t("إعادة الإرسال بعد ")+remaining+t(" ثانية"):t("إعادة إرسال الرمز")}}</button></p><div v-if="challenge?.previewCode" class="auth-preview-note" role="status"><strong>{{t("رمز التحقق")}}</strong><span>{{t("واتساب غير مربوط بهذه النسخة لم تُرسل رسالة؛ استخدم الرمز التالي:")}}</span><b dir="ltr">{{challenge?.previewCode}}</b></div></div>
      </div>
      <div class="auth-bottom"><p class="auth-switch" v-if="view!=='otp'">{{view==='login'?t("ليس لديك حساب؟"):t("لديك حساب؟")}} <button type="button" @click="change(view==='login'?'register':'login')" :disabled="busy">{{view==='login'?t("إنشاء حساب"):t("تسجيل الدخول")}}</button></p><span class="auth-footer" dir="ltr">FATYA · DIGITAL STORE</span></div>
    </section>
  </div>`
};
