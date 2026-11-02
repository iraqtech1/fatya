import ProviderOfferImages from './ProviderOfferImages.js';
import {providerDetailFields,providerFieldLabel,providerFieldValue} from './provider-detail-fields.js';
export default {
 components:{ProviderOfferImages},
 props:{offer:Object},
 setup(){return{providerDetailFields,providerFieldLabel,providerFieldValue};},
 template:`<section class="provider-offer-details"><ProviderOfferImages :offer="offer"/><div v-if="offer.supplierDetails?.length" class="admin-table-wrap" tabindex="0" role="region" aria-label="تفاصيل الفئة"><table><thead><tr><th>التفصيل</th><th>القيمة</th></tr></thead><tbody><tr v-for="(field,i) in providerDetailFields(offer)" :key="field.label"><td>{{providerFieldLabel(field.label,i)}}</td><td dir="auto">{{providerFieldValue(field)}}</td></tr></tbody></table></div></section>`
};
