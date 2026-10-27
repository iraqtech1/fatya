import {computed} from 'vue/dist/vue.esm-bundler.js';
import {Headphones} from 'lucide-vue-next';
import {t} from './i18n.js';
import {faqRows,faqText} from './faqs.js';
export default {
  components:{Headphones},props:{settings:Object},
  setup(props){return {t,rows:computed(()=>faqRows(props.settings)),text:(entry,key)=>faqText(entry,key,t)};},
  template:`<div class="faq"><Headphones :size="36"/><h2>{{t('إجابات سريعة')}}</h2><p v-if="settings?.supportEmail" dir="ltr">{{settings.supportEmail}}</p><details v-for="faq in rows" :key="faq.id"><summary dir="auto">{{text(faq,'question')}}</summary><p dir="auto" style="white-space:pre-wrap;overflow-wrap:anywhere">{{text(faq,'answer')}}</p></details></div>`
};
