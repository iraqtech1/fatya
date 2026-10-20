import {ChevronLeft} from 'lucide-vue-next';
import {t} from './i18n.js';

// One brand opens its existing denomination and checkout flow.
export default {
  components:{ChevronLeft},
  props:{products:{type:Array,required:true},label:{type:String,required:true}},
  emits:['select'],
  setup(){return {t};},
  template:`<div class="store-card-grid" :aria-label="label">
    <button v-for="product in products" :key="product.id" type="button" class="store-card" @click="$emit('select',product)" :aria-label="t('اختيار فئة ')+t(product.name)">
      <span class="store-card-art" :style="{'--card-color':product.color}">
        <img v-if="product.image" :src="product.image" alt="" loading="lazy"/>
        <strong v-else dir="ltr">{{product.en}}</strong>
      </span>
      <span class="store-card-name">{{t(product.name)}}</span>
      <span class="store-card-action">{{t('اختر الفئة')}} <ChevronLeft :size="13" aria-hidden="true"/></span>
    </button>
  </div>`
};
