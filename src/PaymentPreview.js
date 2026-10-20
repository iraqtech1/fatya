import {t} from './i18n.js';
import {computed,ref} from 'vue/dist/vue.esm-bundler.js';
import {ArrowRight,Check,ShieldCheck} from 'lucide-vue-next';
import './payment-preview.css';
export default {
 components:{ArrowRight,Check,ShieldCheck},
 props:{checkout:Object,receipt:{type:String,default:''}},emits:['confirm','back','orders'],
 setup(props){const amount=computed(()=>new Intl.NumberFormat('en-US').format(props.checkout.amount));const buyer=ref(''),account=ref(''),expiry=ref('');return {t,amount,buyer,account,expiry};},
 template:`<section class="payment-preview" :aria-label="t('صفحة الدفع')">
 <div class="payment-page-heading"><button v-if="!receipt" class="account-back" @click="$emit('back')"><ArrowRight :size="18"/>{{t('تأكيد عملية الدفع')}}</button></div>
 <template v-if="!receipt">
 <h2 class="payment-section-title">{{t('تفاصيل الطلب')}}</h2>
 <dl class="payment-order-panel"><div><dt>{{t('رقم الحركة')}}</dt><dd dir="ltr">{{checkout.reference}}</dd></div><div><dt>{{t('اسم التاجر')}}</dt><dd>{{t('فاتيا')}}</dd></div><div><dt>{{t('البطاقة')}}</dt><dd>{{t(checkout.product.name)}} · {{checkout.product.values[checkout.denom]}} {{t(checkout.product.unit||'د.ع')}}</dd></div><div><dt>{{t('الكمية')}}</dt><dd>{{checkout.quantity}}</dd></div></dl>
 <h2 class="payment-section-title">{{t('تفاصيل عملية الدفع')}}</h2>
 <div class="payment-method-summary"><span class="payment-preview-brand" :class="{zaincash:checkout.method==='ZainCash'}"><img :src="checkout.method==='Qi'?'payments/qi.svg':'payments/zaincash.svg'" :alt="checkout.method"/></span><div><strong>{{t('الدفع بواسطة')}} {{checkout.method}}</strong></div><ShieldCheck :size="22"/></div>

 <form class="payment-details-form" @submit.prevent="$emit('confirm')"><div class="payment-fields-panel">
 <label>{{t('اسم المشتري')}}<input v-model="buyer" required maxlength="80" autocomplete="off" :placeholder="t('اسم')"/></label>
 <label v-if="checkout.method==='Qi'">{{t('رقم البطاقة')}}<input v-model="account" required inputmode="numeric" pattern="[0-9]{16}" maxlength="16" dir="ltr" placeholder="4242424242424242" autocomplete="off"/></label>
 <label v-else>{{t('رقم محفظة زين كاش')}}<input v-model="account" required inputmode="numeric" pattern="07[0-9]{9}" @input="account=$event.target.value.replace(/[^0-9]/g,'');$event.target.value=account" maxlength="11" dir="ltr" placeholder="07700000000" autocomplete="off"/></label>
 <label v-if="checkout.method==='Qi'">{{t('تاريخ الانتهاء')}}<input v-model="expiry" required pattern="(0[1-9]|1[0-2])/[0-9]{2}" maxlength="5" dir="ltr" placeholder="12/30" autocomplete="off"/></label>
 </div><div class="payment-amount-row"><span>{{t('مبلغ الطلب')}}</span><strong>{{amount}} {{t('د.ع')}}</strong></div><div class="payment-amount-row payment-final-total"><span>{{t('المبلغ الكلي')}}</span><strong>{{amount}} {{t('د.ع')}}</strong></div><button class="primary wide payment-confirm" type="submit">{{t('تأكيد')}} · {{amount}} {{t('د.ع')}}</button></form></template>
 <div v-else class="payment-preview-card success"><span><Check :size="36"/></span><h2>{{t('تم تأكيد الدفع')}}</h2><p>{{t('أضفنا طلبك إلى طلباتي')}}</p><p dir="ltr">{{receipt}}</p><button class="primary wide" @click="$emit('orders')">{{t('عرض طلباتي')}}</button></div>
 </section>`
};
