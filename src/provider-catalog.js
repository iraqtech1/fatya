export const serviceTypeLabel=type=>({topup:'بطاقات الرصيد والتعبئة',bundle:'الباقات',bill:'الفواتير',standard:'البطاقات',premium:'Premium'}[type]||type);
export function groupProviderCatalog(offers){
  const index=new Map(offers.map(o=>[o.type+':'+o.providerId,o])),groups=new Map(),parents=new Set(offers.filter(o=>o.parentId).map(o=>o.type+':'+o.parentId));
  for(const offer of offers){
    const parentId=offer.parentId&&offer.parentId!=='0'?offer.parentId:null;
    const parent=parentId?index.get(offer.type+':'+parentId):null;
    const hasChildren=parents.has(offer.type+':'+offer.providerId);
    const id=parentId||(hasChildren?offer.providerId:'root');const key=offer.type+':'+id;
    if(!groups.has(key))groups.set(key,{key,type:offer.type,parentId:id==='root'?null:id,title:parent?.title||(hasChildren?offer.title:parentId?'فئة '+parentId:serviceTypeLabel(offer.type)),rows:[]});
    groups.get(key).rows.push(offer);
  }
  return [...groups.values()];
}
export async function refreshProviderCatalog(call,provider,provinceId){
  const calls=[call('/admin/procurement/digital/catalog',{provider,...provider==='rabiaa'?{provinceId}:{}})];
  if(provider==='topup')calls.push(call('/admin/procurement/digital/balance'));
  const result=await Promise.allSettled(calls);
  return {catalog:result[0].status==='fulfilled'?result[0].value:null,balance:result[1]?.status==='fulfilled'?result[1].value:null,errors:result.map((r,i)=>r.status==='rejected'?(i===0?'تعذّر تحديث الفئات والباقات: ':'تعذّر تحديث رصيد TOPUP: ')+r.reason.message:'').filter(Boolean)};
}
