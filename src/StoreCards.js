import {ChevronLeft} from 'lucide-vue-next';
import {t} from './i18n.js';

// One brand opens its existing denomination and checkout flow.
export default {
  components:{ChevronLeft},
  props:{products:{type:Array,required:true},label:{type:String,required:true},variant:{type:String,default:'cards'}},
  emits:['select'],
  setup(){return {t};},
  template:`<div class="store-card-grid" :class="['store-variant-'+variant]" :aria-label="label">
    <button v-for="product in products" :key="product.id" type="button" class="store-card" @click="$emit('select',product)" :aria-label="t('فتح ')+t(product.name)">
      <span class="store-card-art" :style="{'--card-color':product.color}">
        <strong v-if="variant==='brands'" class="store-brand-wordmark" dir="ltr">{{product.en}}</strong><img v-else-if="product.image" :src="product.image" alt="" loading="lazy"/>
        <strong v-else dir="ltr">{{product.en}}</strong>
      </span>
      <span class="store-card-name">{{t(product.name)}}</span>
      <span v-if="variant==='cards'" class="store-card-action">{{t('اختر الفئة')}} <ChevronLeft :size="13" aria-hidden="true"/></span>
    </button>
  </div>`
};
