import {ref,computed} from 'vue/dist/vue.esm-bundler.js';
import {can} from './admin-access.js';
import ReportsCenter from './ReportsCenter.js';
import DigitalHistoryPanel from './DigitalHistoryPanel.js';
import RabiaaActivation from './RabiaaActivation.js';
export default {
 components:{ReportsCenter,DigitalHistoryPanel,RabiaaActivation},
 setup(){const year=ref(new Date().getFullYear()),month=ref(''),scope=ref(null);const selected=computed(()=>{const from=year.value+'-'+String(month.value||1).padStart(2,'0')+'-01',last=month.value?new Date(Date.UTC(year.value,Number(month.value),0)).getUTCDate():31;return {from,to:year.value+'-'+String(month.value||12).padStart(2,'0')+'-'+last};});function apply(){scope.value={...selected.value};}apply();return {can,year,month,scope,apply};},
 template:`<section class="procurement archive-center"><section class="admin-panel"><h2>الأرشيف الإداري بالتاريخ</h2><p>قراءة السجلات الأصلية ضمن صلاحيات تقاريرك، بما فيها المبيعات المخفية من قائمة الإدارة. الأرشفة لا تحذف سجلات مالية أو ملفات ورود ولا تغير ملكية بطاقات الزبائن.</p><form class="archive-period-form" @submit.prevent="apply"><label>السنة<input v-model.number="year" type="number" min="2000" max="9999" required/></label><label>الشهر<select v-model="month"><option value="">السنة كاملة</option><option v-for="number in 12" :value="number">{{number}}</option></select></label><button type="submit" class="admin-primary">فتح فترة الأرشيف</button></form><div class="archive-period-summary"><span>الفترة المحددة</span><strong><bdi>{{scope.from}}</bdi><span aria-hidden="true"> ← </span><bdi>{{scope.to}}</bdi></strong><span>بتوقيت بغداد</span></div><p class="archive-period-help">يمكنك تعديل الفترة داخل التقرير وتصدير نتائجه المصرح بها.</p></section><ReportsCenter :key="scope.from+scope.to" :initial-filters="scope"/><DigitalHistoryPanel v-if="can('digital.executions')" :key="'digital'+scope.from+scope.to" :initial-filters="scope"/><RabiaaActivation v-if="can('digital.activation_view')" admin :allow-submit="false" :key="'activation'+scope.from+scope.to" :initial-filters="scope"/></section>`
};

