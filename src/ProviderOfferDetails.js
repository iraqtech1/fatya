import {ref,watch} from 'vue/dist/vue.esm-bundler.js';
export default {
 props:{offer:Object},
 setup(props){const failed=ref([]);watch(()=>props.offer,()=>{failed.value=[];});return{failed};},
 template:`<section class="provider-offer-details"><h3>معلومات الفئة من المجهّز</h3><p>سعر المجهّز <bdi>{{offer.cost??'غير متوفر'}} IQD</bdi></p><p v-if="offer.description">{{offer.description}}</p><div v-if="offer.images?.length" class="provider-image-gallery"><template v-for="src in offer.images" :key="src"><img v-if="!failed.includes(src)" :src="src" :alt="offer.title" loading="lazy" decoding="async" referrerpolicy="no-referrer" @error="failed.push(src)"/><span v-else>تعذّر تحميل صورة المجهّز</span></template></div><p v-if="offer.imageSource==='category'" class="proc-note">الصورة من الفئة الأم لدى المجهّز.</p><div v-if="offer.supplierDetails?.length" class="admin-table-wrap" tabindex="0" role="region" aria-label="جميع معلومات المجهّز"><table><thead><tr><th>الحقل لدى المجهّز</th><th>القيمة المستوردة</th></tr></thead><tbody><tr v-for="(field,i) in offer.supplierDetails" :key="i"><td dir="auto">{{field.label}}</td><td dir="auto">{{field.value}}</td></tr></tbody></table></div></section>`
};
