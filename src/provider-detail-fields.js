import {serviceTypeLabel} from './provider-catalog.js';

const labels={id:'المعرّف',parent_id:'معرّف الفئة الأم',type:'نوع الخدمة',title:'الاسم',name:'الاسم',price:'السعر',id_show:'معرّف العرض',img:'الصورة',description:'الوصف',offer_id:'معرّف العرض لدى المجهّز',product_id:'معرّف المنتج',show2site:'الظهور في الموقع',photo_url:'رابط الصورة',cities:'المدن',city:'المدينة',provinces:'المحافظات',province:'المحافظة',province_id:'معرّف المحافظة',city_id:'معرّف المدينة',duration:'المدة',duration_days:'المدة بالأيام',validity:'الصلاحية',validity_days:'الصلاحية بالأيام',data_amount:'حجم البيانات',internet_data:'بيانات الإنترنت',voice_minutes:'دقائق الاتصال',minutes:'الدقائق',sms:'الرسائل',quota:'الحصة',currency:'العملة',operator:'شركة الاتصالات',network:'الشبكة',brand:'العلامة التجارية',country:'البلد',region:'المنطقة',status:'الحالة',available:'متوفرة',enabled:'مفعّلة',active:'نشطة',amount:'القيمة',value:'القيمة',cost:'التكلفة',balance:'الرصيد',quantity:'الكمية',limit:'الحد',minimum:'الحد الأدنى',maximum:'الحد الأعلى',code:'الرمز',category:'الفئة',category_id:'معرّف الفئة',details:'التفاصيل',short_description:'الوصف المختصر',description_ar:'الوصف العربي',image:'الصورة',images:'الصور',photo:'الصورة',url:'الرابط',src:'المصدر',path:'المسار',created_at:'تاريخ الإنشاء',updated_at:'آخر تحديث'};
const normalize=key=>String(key).replace(/([a-z0-9])([A-Z])/g,'$1_$2').toLowerCase();
const sensitive=key=>/password|secret|credential|authorization|private.?key|api.?key|access.?token|bearer|(^|[_.-])(pin|cvv|cvc|token)($|[_.-])/i.test(normalize(key));
const present=value=>value!==null&&value!==undefined&&String(value).trim()!==''&&String(value).trim()!=='—';
const core=new Set(['id','title','type','price']);
const imageKey=key=>/^(img|image|images|photo|photo_url|image_url|picture|thumbnail|logo|icon|banner)$/.test(normalize(key));

export function providerFieldLabel(key,index=0){
 return String(key).split('.').map(part=>/^\d+$/.test(part)?String(Number(part)+1):labels[normalize(part)]||(/[\u0600-\u06ff]/.test(part)?part:'تفصيل إضافي '+(index+1))).join(' / ');
}
export function providerFieldValue(field){
 if(!present(field.value))return '—';
 const key=normalize(field.label.split('.').at(-1)),value=String(field.value);
 if(key==='type')return serviceTypeLabel(value);
 if(value==='true')return 'نعم';if(value==='false')return 'لا';
 if(['show2site','available','active','enabled'].includes(key)&&['0','1'].includes(value))return value==='1'?'نعم':'لا';
 return value;
}
export function providerDetailFields(offer){
 return (offer.supplierDetails||[]).filter(f=>!sensitive(f.label));
}
export function providerDetailColumns(offers){
 const keys=new Set();
 for(const offer of offers)for(const f of providerDetailFields(offer)){
  const root=f.label.split('.')[0];
  if(!core.has(normalize(root))&&!imageKey(root)&&present(f.value))keys.add(root);
 }
 return [...keys].map((key,index)=>({key,label:providerFieldLabel(key,index)}));
}
export function providerDetailEntries(offer,key){
 return providerDetailFields(offer).filter(f=>(f.label===key||f.label.startsWith(key+'.'))&&present(f.value)).map((f,index)=>({label:f.label===key?'':providerFieldLabel(f.label.slice(key.length+1),index),value:providerFieldValue(f)}));
}
