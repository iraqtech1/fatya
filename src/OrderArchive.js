import {ref,computed} from 'vue/dist/vue.esm-bundler.js';
import {Archive,Search,ChevronDown,CreditCard} from 'lucide-vue-next';
import {t} from './i18n.js';
import {store,number} from './store.js';
import PagedList from './PagedList.js';
import './order-archive.css';

export default {
  components:{Archive,Search,ChevronDown,CreditCard,PagedList},
  props:{orders:{type:Array,default:()=>[]}},emits:['browse'],
  setup(props){
    const query=ref('');
    const filtered=computed(()=>{
      const term=query.value.trim().toLocaleLowerCase();
      return props.orders.filter(o=>[o.name,t(o.name),o.id,o.date,String(o.value)].some(value=>String(value).toLocaleLowerCase().includes(term)));
    });
    const unitFor=order=>order.unit||store.products.find(p=>p.id===order.productId)?.unit||'د.ع';
    return {t,number,query,filtered,unitFor};
  },
  template:`<section class="order-archive" :aria-label="t('ارشيف الطلبات')">
    <div class="archive-heading"><span class="archive-icon"><Archive :size="26"/></span><div><h2>{{t('ارشيف الطلبات')}}</h2><p>{{t('كل طلباتك السابقة وتفاصيل بطاقاتك، بمكان واحد.')}}</p></div><b class="archive-count">{{number(orders.length)}}</b></div>
    <div v-if="!orders.length" class="archive-empty account-panel"><CreditCard :size="36"/><h3>{{t('أرشيفك بانتظار أول بطاقة')}}</h3><p>{{t('بعد الشراء، تلقى طلبك هنا وتگدر ترجع لتفاصيله بأي وقت.')}}</p><button class="primary" @click="$emit('browse')">{{t('اكتشف البطاقات')}}</button></div>
    <template v-else>
      <label class="archive-search"><Search :size="19"/><input v-model="query" type="search" :aria-label="t('البحث في ارشيف الطلبات')" :placeholder="t('ابحث باسم البطاقة أو رقم الطلب')"/></label>
      <p v-if="!filtered.length" class="archive-empty account-panel" role="status">{{t('ماكو طلبات مطابقة للبحث.')}}</p>
      <PagedList :items="filtered" :reset-key="query" :size="6" v-slot="{items}">
        <details class="archive-order" v-for="order in items" :key="order.id">
          <summary><span class="archive-card-icon"><CreditCard :size="23"/></span><span class="archive-order-title"><strong>{{t(order.name)}}</strong><small>{{number(order.value)}} {{t(unitFor(order))}} · <time dir="ltr">{{order.date}}</time></small></span><span class="archive-status" :class="{'is-cancelled':order.status==='ملغي'}">{{t(order.status)}}</span><ChevronDown class="archive-chevron" :size="18"/></summary>
          <div class="archive-details"><dl><div><dt>{{t('رقم الطلب')}}</dt><dd dir="ltr">{{order.id}}</dd></div><div><dt>{{t('فئة البطاقة')}}</dt><dd>{{number(order.value)}} {{t(unitFor(order))}}</dd></div><div><dt>{{t('الكمية')}}</dt><dd>{{number(order.quantity)}}</dd></div><div><dt>{{t('طريقة الدفع')}}</dt><dd dir="ltr">{{order.payment}}</dd></div><div><dt>{{t('المجموع')}}</dt><dd>{{number(order.price)}} {{t('د.ع')}}</dd></div></dl></div>
        </details>
      </PagedList>
    </template>
  </section>`
};
