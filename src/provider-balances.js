import {api} from './api.js';
export async function loadProviderBalances(request=api){
  const cards=[{id:'topup',label:'رصيد TOPUP',balance:null,note:''},{id:'rabiaa',label:'رصيد الرابعة',balance:null,note:''}];
  let connections;try{connections=(await request('/admin/procurement/digital')).connections;if(!Array.isArray(connections))throw Error('Invalid balance status');}catch{for(const c of cards)c.note='تعذّر جلب بيانات الرصيد';return cards;}
  for(const card of cards){const c=connections.find(c=>c.id===card.id);
    if(card.id!=='topup'||!c?.balanceSupported){card.note='رصيد المزوّد غير متاح عبر API الحالي';continue;}
    if(!c.configured){card.note='بانتظار إعداد الربط';continue;}
    card.balance=c.balance??null;card.note=card.balance!==null?'رصيد محفوظ':'';
    try{const result=await request('/admin/procurement/digital/balance');card.balance=result.balance;card.note='الرصيد المتاح لدى المزوّد';}
    catch{card.note=card.balance!==null?'رصيد محفوظ؛ تعذّر تحديثه':'تعذّر جلب الرصيد';}
  }
  return cards;
}
