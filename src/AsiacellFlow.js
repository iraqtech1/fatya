import {ref,computed,nextTick,onBeforeUnmount} from 'vue/dist/vue.esm-bundler.js';
import {ArrowRight,ChevronLeft,CreditCard,Smartphone,Wifi,X,Check,Plus,Minus,RotateCcw} from 'lucide-vue-next';
import {t} from './i18n.js';
import {limitPhoneInput} from './phone-input.js';
import PaymentMethods from './PaymentMethods.js';
import {ASIACELL_SERVICES,serviceOffers,buildAsiacellCheckout} from './asiacell-services.js';
import './asiacell-flow.css';

export default {
  components:{ArrowRight,ChevronLeft,CreditCard,Smartphone,Wifi,X,Check,Plus,Minus,RotateCcw,PaymentMethods},
  props:{product:{type:Object,required:true},preview:Boolean,initialDraft:Object},
  emits:['back','checkout'],
  setup(props,{emit}){
    const draft=props.initialDraft;
    const service=ref(draft?.service||''),index=ref(draft?.index??null),quantity=ref(draft?.quantity||1),recipient=ref(draft?.recipient||''),method=ref(draft?.method||'Qi');
    const sheet=ref(null),heading=ref(null),error=ref('');
    const icons={CreditCard,Smartphone,Wifi};
    const refundNotice='إذا فشل طلبك نقوم باسترداد المبلغ تلقائياً خلال أقل من 24 ساعة.';
    const current=computed(()=>ASIACELL_SERVICES.find(item=>item.id===service.value));
    const offers=computed(()=>serviceOffers(props.product,service.value,props.preview));
    const selected=computed(()=>offers.value[index.value]);
    const total=computed(()=>(selected.value?.price||0)*(service.value==='cards'?quantity.value:1));
    const number=value=>new Intl.NumberFormat('en-US').format(value);
    const offerTitle=offer=>offer.label?t(offer.label):number(offer.value)+' '+t(props.product.unit||'د.ع');
    async function focusHeading(){await nextTick();heading.value?.focus();}
    function chooseService(id){service.value=id;index.value=null;quantity.value=1;error.value='';focusHeading();}
    function back(){if(service.value){service.value='';index.value=null;focusHeading();}else emit('back');}
    async function chooseOffer(value){index.value=value;quantity.value=1;error.value='';await nextTick();sheet.value?.showModal();}
    function closeSheet(){sheet.value?.close();error.value='';}
    async function reopen(){error.value='';await nextTick();sheet.value?.showModal();}
    function proceed(){try{
      const checkout=buildAsiacellCheckout(props.product,{service:service.value,index:index.value,quantity:quantity.value,recipient:recipient.value,method:method.value},props.preview);
      closeSheet();emit('checkout',checkout);
    }catch(e){error.value=e.message;}}
    onBeforeUnmount(()=>sheet.value?.close());
    return {refundNotice,limitPhoneInput,t,services:ASIACELL_SERVICES,icons,service,index,quantity,recipient,method,sheet,heading,error,current,offers,selected,total,number,offerTitle,chooseService,chooseOffer,back,closeSheet,reopen,proceed};
  },
  template:`<section class="asiacell-flow" :aria-label="t(product.name)">
    <div class="telecom-breadcrumb"><button type="button" @click="back"><ArrowRight :size="18"/><span>{{service?t(product.name):t('رجوع')}}</span></button><span v-if="service">{{t(current.label)}}</span></div>
    <div class="telecom-title"><div><p>{{t('اتصالات')}}</p><h1 ref="heading" tabindex="-1">{{service?t(current.label):t(product.name)}}</h1></div><span class="asiacell-wordmark" dir="ltr">Asiacell</span></div>
    <template v-if="!service"><p class="telecom-instruction">{{t('اختار إحدى الخدمات')}}</p>
      <div class="telecom-services"><button v-for="item in services" :key="item.id" type="button" class="telecom-service" @click="chooseService(item.id)"><span class="telecom-service-icon"><component :is="icons[item.icon]" :size="24"/></span><span class="telecom-service-copy"><strong>{{t(item.label)}}</strong><small>{{t(item.description)}}</small></span><ChevronLeft :size="19"/></button></div>
    </template>
    <template v-else><p class="telecom-instruction">{{t(service==='direct'?'اختار نوع التعبئة':service==='bundles'?'اختار إحدى الباقات':'اختار إحدى الفئات')}}</p>
      <div v-if="offers.length" class="telecom-offers" role="radiogroup" :aria-label="t(current.label)"><label v-for="(offer,i) in offers" :key="offer.value" class="telecom-offer" :class="{'is-selected':index===i}"><input type="radio" name="asiacell-offer" :checked="index===i" @change="chooseOffer(i)"/><span class="telecom-offer-copy"><strong>{{offerTitle(offer)}}</strong><small v-if="offer.description">{{t(offer.description)}}</small></span><span class="telecom-offer-price">{{number(offer.price)}} <small>{{t('د.ع')}}</small></span></label></div>
      <div v-else class="telecom-unavailable"><Wifi :size="30"/><h2>{{t('هذه الخدمة قيد التجهيز')}}</h2><p>{{t('تظهر الفئات والأسعار بعد تفعيل الخدمة')}}</p></div>
      <p v-if="offers.length" class="telecom-refund-note" role="note"><RotateCcw :size="16" aria-hidden="true"/><span>{{t(refundNotice)}}</span></p>
      <button v-if="selected" type="button" class="primary wide telecom-continue" @click="reopen">{{t('متابعة')}} · {{number(total)}} {{t('د.ع')}}</button>
    </template>
    <dialog ref="sheet" class="telecom-sheet" @cancel="error=''" :aria-label="t('تفاصيل الطلب')"><template v-if="selected"><div class="telecom-sheet-heading"><div><p>{{t(current.label)}}</p><h2>{{offerTitle(selected)}}</h2></div><button type="button" @click="closeSheet" :aria-label="t('إغلاق')"><X :size="20"/></button></div>
      <form @submit.prevent="proceed">
        <label v-if="service!=='cards'" class="telecom-recipient">{{t('رقم آسياسيل المراد شحنه')}}<input v-model="recipient" @input="recipient=limitPhoneInput($event)" type="tel" inputmode="numeric" autocomplete="tel-national" dir="ltr" minlength="11" maxlength="11" pattern="07[0-9]{9}" required placeholder="07700000000"/><small>{{t('تأكد من الرقم قبل المتابعة')}}</small></label>
        <div v-else class="quantity-row"><span>{{t('الكمية')}}</span><div class="stepper"><button type="button" :disabled="quantity<=1" @click="quantity--" :aria-label="t('تقليل الكمية')"><Minus :size="18"/></button><span>{{quantity}}</span><button type="button" :disabled="quantity>=20" @click="quantity++" :aria-label="t('زيادة الكمية')"><Plus :size="18"/></button></div></div>
        <PaymentMethods v-model="method"/><div class="total"><span>{{t('المجموع')}}</span><strong>{{number(total)}} <small>{{t('د.ع')}}</small></strong></div><p v-if="error" class="telecom-error" role="alert">{{t(error)}}</p><p class="telecom-refund-note" role="note"><RotateCcw :size="16" aria-hidden="true"/><span>{{t(refundNotice)}}</span></p><button type="submit" class="primary wide">{{t('متابعة للدفع')}}</button>
      </form></template></dialog>
  </section>`
};
