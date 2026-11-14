import {ref,watch} from 'vue/dist/vue.esm-bundler.js';

// Watch form fields and file identity without traversing 50,000 card cells on typing.
export function useStockOrderPreview({supplierId,supplier,source,city,lines,revision}){
  const preview=ref(null),exclude=ref(false),requestId=ref(''),previewNotice=ref('');let generation=0;
  function invalidate(){if(preview.value)previewNotice.value='تغيرت بيانات الطلبية؛ اطلب معاينة جديدة قبل الاعتماد';generation++;preview.value=null;exclude.value=false;requestId.value='';}
  watch(()=>[supplierId?.value,supplier.value,source.value,city.value,revision.value,...lines.value.flatMap(l=>[l.id,l.productId,l.manufacturerId,l.supplierCompanyId,l.denom,l.cost,l.retail,l.newValue,l.newName,l.expiry,l.dateFormat,l.twoDigitPivot,l.monthEnd,l.skipHeader,l.pinColumn,l.serialColumn,l.expiryColumn,l.cvcColumn,l.referenceColumn,l.categoryCode,l.paste,l.raw])],invalidate,{flush:'sync'});
  function begin(){invalidate();previewNotice.value='';return generation;}
  function accept(result,token){if(token!==generation)return false;preview.value=result;requestId.value=crypto.randomUUID();return true;}
  return {preview,exclude,requestId,previewNotice,invalidate,begin,accept};
}
