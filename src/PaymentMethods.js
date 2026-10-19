import {Check} from 'lucide-vue-next';
import {t} from './i18n.js';
export default {
  components:{Check},props:{modelValue:{type:String,default:'Qi'}},emits:['update:modelValue'],
  setup(){return {t};},
  template:`<section class="payment-methods" :aria-label="t('طرق الدفع')"><h3>{{t('الدفع بواسطة')}}</h3><div class="payment-method-grid">
    <button v-for="method in ['Qi','ZainCash']" :key="method" type="button" :class="{selected:modelValue===method}" :aria-pressed="modelValue===method" @click="$emit('update:modelValue',method)" :aria-label="t('الدفع بواسطة')+' '+method">
      <span class="payment-logo" :class="{zaincash:method==='ZainCash'}"><img :src="method==='Qi'?'payments/qi.svg':'payments/zaincash.svg'" alt=""/></span><span dir="ltr">{{method}}</span><span class="payment-selection-indicator" aria-hidden="true"><Check v-if="modelValue===method" :size="14" :stroke-width="3"/></span>
    </button>
  </div></section>`
};
