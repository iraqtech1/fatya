import {denominationOptions} from './product-metadata.js';
import {normalizePhone} from './auth-preview.js';

export const ASIACELL_SERVICES=[
  {id:'cards',label:'كارتات رصيد',description:'اختار كارت الرصيد والفئة المناسبة',icon:'CreditCard'},
  {id:'direct',label:'شحن رصيد مباشر',description:'تعبئة الرصيد على رقم آسياسيل',icon:'Smartphone'},
  {id:'bundles',label:'باقات وعروض آسياسيل',description:'باقات الإنترنت والمكالمات',icon:'Wifi'},
];

// Sample offers are available only in local/static preview mode. They are not
// provider prices and must never be submitted to the shared order API.
const previewDirect=[10000,15000,25000,30000,35000].map(value=>({value,price:value}));
const previewBundles=[
  {value:1,price:5000,label:'باقة RED5',description:'إنترنت ومكالمات'},
  {value:2,price:10000,label:'باقة RED10',description:'إنترنت ومكالمات'},
  {value:3,price:15000,label:'باقة RED15',description:'إنترنت ومكالمات'},
  {value:4,price:5000,label:'3 جيجابايت إنترنت',description:'باقة أسبوعية'},
  {value:5,price:10000,label:'5 جيجابايت إنترنت',description:'باقة شهرية'},
];

export function isAsiacell(product){
  const name=`${product?.name||''} ${product?.en||''}`.normalize('NFKC').replace(/[أإآ]/g,'ا').replace(/[\s_-]/g,'').toLowerCase();
  return /اسياسيل|asiacell/.test(name)||!!product?.providerServices?.length;
}

export function serviceOffers(product,service,preview=false){
  if(service==='cards')return denominationOptions(product).map(({value,index,name,description})=>({value,price:product.prices[index],image:product.denomImages?.[index]||'',index,...(name?{label:name,customLabel:true}:{}),...(description?{description,customDescription:true}: {})})).filter(o=>product?.digitalOffers?.[o.index]?.provider!=='topup');
  if(!preview&&product?.providerServices?.length&&service!=='cards'){return product.providerServices.filter(o=>service==='direct'?o.type==='topup':service==='bundles'?['bundle','bill'].includes(o.type):false).map(o=>({value:o.id,index:0,publicationId:o.id,price:Number(o.price),label:o.title,description:o.description,image:o.images?.[0]||'',serviceInfo:o.serviceInfo||[],groupPath:o.groupPath||[],customLabel:true,customDescription:true,digital:true,manual:true,purchasable:o.purchasable,type:o.type}));}
  if(!preview)return Object.entries(product?.digitalOffers||{}).filter(([,o])=>o.provider==='topup'&&(service==='direct'?o.type==='topup':service==='bundles'?['bundle','bill'].includes(o.type):false)).map(([index,o])=>({value:product.values[+index],price:product.prices[+index],index:+index,label:product.denomNames?.[+index]||o.title,description:product.denomDescriptions?.[+index]||'',customLabel:!!product.denomNames?.[+index],customDescription:!!product.denomDescriptions?.[+index],digital:true}));
  const rows=service==='direct'?previewDirect:service==='bundles'?previewBundles:[];
  return rows.map((row,index)=>({...row,index}));
}

export function buildAsiacellCheckout(product,{service,index,quantity=1,recipient='',method='Qi'},preview=false){
  const offer=serviceOffers(product,service,preview)[index];
  if(!offer||!Number.isInteger(index)||!Number.isInteger(quantity)||quantity<1||quantity>20)throw Error('اختار فئة صالحة للمتابعة');
  if(offer.publicationId&&!offer.purchasable)throw Error('هذه الفئة منشورة ولكنها غير متاحة للطلب حالياً');
  if(!['Qi','ZainCash'].includes(method))throw Error('وسيلة الدفع غير صحيحة');
  const phone=normalizePhone(recipient);
  if(service!=='cards'&&!/^07\d{9}$/.test(phone))throw Error('اكتب رقم هاتف عراقي صحيح من 11 رقم');
  const serviceLabel=ASIACELL_SERVICES.find(item=>item.id===service)?.label;
  const sample=service!=='cards'&&!offer.digital;
  const checkoutProduct=offer.publicationId?{...product,providerPublicationId:offer.publicationId,values:[null],prices:[offer.price],stock:[0],denomNames:[offer.label],denomDescriptions:[offer.description],digitalOffers:{0:{provider:'topup',type:offer.type,title:offer.label}}}:sample?{
    ...product,name:product.name+' · '+serviceLabel,
    values:[offer.value],prices:[offer.price],stock:[0],denomImages:[],
    unit:service==='bundles'?'باقة':product.unit||'د.ع',
  }:product;
  return {product:checkoutProduct,denom:sample?0:offer.index,quantity:service==='cards'?quantity:1,
    amount:offer.price*(service==='cards'?quantity:1),method:offer.publicationId?'Manual':method,
    telecom:{service,label:serviceLabel,offerLabel:offer.label||'',recipient:service==='cards'?'':phone},
    demoOnly:sample,
    asiacellDraft:{service,index,quantity:service==='cards'?quantity:1,recipient:service==='cards'?'':phone,method},
  };
}
