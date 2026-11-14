import {computed} from 'vue/dist/vue.esm-bundler.js';
import {ChevronLeft,ArrowRight,Building2,CreditCard} from 'lucide-vue-next';
import {catalogCompanies,companyDenominations} from './company-catalog.js';
import {t} from './i18n.js';
import './company-catalog.css';

export default {
 components:{ChevronLeft,ArrowRight,Building2,CreditCard},
 props:{products:Array,query:{type:String,default:''},modelValue:{type:Object,default:()=>({})}},emits:['update:modelValue','select','categories'],
 setup(props,{emit}){
  const companies=computed(()=>catalogCompanies(props.products||[],props.query));
  const company=computed(()=>companies.value.find(c=>c.id===props.modelValue.company));
  const denominations=computed(()=>companyDenominations(company.value));
  const denomination=computed(()=>denominations.value.find(d=>d.id===props.modelValue.denomination));
  function chooseCompany(c){emit('update:modelValue',{company:c.id,denomination:''});}
  function chooseDenomination(d){if(d.cards.length===1){emit('select',d.cards[0]);return;}emit('update:modelValue',{company:company.value.id,denomination:d.id});}
  function back(){emit('update:modelValue',denomination.value?{company:company.value.id,denomination:''}:{});}
  return{t,companies,company,denominations,denomination,chooseCompany,chooseDenomination,back,number:v=>new Intl.NumberFormat('en-US').format(v)};
 },
 template:`<section class="company-catalog"><nav class="company-catalog-path" aria-label="مسار التصنيف والشركة والفئة"><button type="button" @click="$emit('categories')">{{t('التصنيفات')}}</button><ChevronLeft :size="15"/><button type="button" @click="$emit('update:modelValue',{})">{{t('الشركات')}}</button><template v-if="company"><ChevronLeft :size="15"/><button type="button" @click="$emit('update:modelValue',{company:company.id,denomination:''})">{{company.name}}</button></template><template v-if="denomination"><ChevronLeft :size="15"/><span>{{denomination.name}}</span></template></nav><div class="company-catalog-heading"><h2>{{denomination?t('الكارتات')+' · '+denomination.name:company?t('الفئات')+' · '+company.name:t('الشركات')}}</h2><button v-if="company" type="button" @click="back"><ArrowRight :size="17"/>{{t('رجوع')}}</button></div><div class="company-catalog-grid"><template v-if="!company"><button v-for="c in companies" :key="c.id" type="button" class="company-catalog-card" @click="chooseCompany(c)"><span class="company-catalog-art"><img v-if="c.image" :src="c.image" alt="" loading="lazy"/><Building2 v-else :size="36"/></span><strong>{{c.name}}</strong><ChevronLeft :size="17"/></button></template><template v-else-if="!denomination"><button v-for="d in denominations" :key="d.id" type="button" class="company-catalog-card" @click="chooseDenomination(d)"><span class="company-catalog-art"><img v-if="d.image" :src="d.image" alt="" loading="lazy"/><CreditCard v-else :size="36"/></span><strong>{{d.name}}</strong><small>{{t(d.unit||'د.ع')}}</small><ChevronLeft :size="17"/></button></template><template v-else><button v-for="card in denomination.cards" :key="card.id" type="button" class="company-catalog-card" @click="$emit('select',card)"><span class="company-catalog-art"><img v-if="card.image" :src="card.image" alt="" loading="lazy"/><CreditCard v-else :size="36"/></span><strong>{{card.name}}</strong><bdi>{{number(card.price)}} {{t('د.ع')}}</bdi><ChevronLeft :size="17"/></button></template></div><p v-if="!companies.length" role="status">{{t('لا توجد نتائج')}}</p></section>`
};
