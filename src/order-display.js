const dayFormat=new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Baghdad',year:'numeric',month:'2-digit',day:'2-digit'});
const clockFormat=new Intl.DateTimeFormat('en-GB',{timeZone:'Asia/Baghdad',hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23'});
function creationDate(order){
  const value=order?.createdAt;
  // Date-only legacy records have no known clock time; never invent midnight.
  if(typeof value==='number'?value<=0:!(typeof value==='string'&&/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})$/i.test(value)))return null;
  const date=new Date(value);return Number.isFinite(date.getTime())?date:null;
}
export function orderDateLabel(order){
  const date=creationDate(order);if(!date)return order?.date||'—';
  const parts=Object.fromEntries(dayFormat.formatToParts(date).map(p=>[p.type,p.value]));return `${parts.year}-${parts.month}-${parts.day}`;
}
export function orderClock(order){const date=creationDate(order);return date?clockFormat.format(date):'';}
export function orderCreatedLabel(order){const clock=orderClock(order);return orderDateLabel(order)+(clock?' · '+clock:'');}
function uuid(id){return String(id??'').match(/^(?:(?:ORDER|FATYA)-)?([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})$/i)?.[1].replaceAll('-','').toUpperCase();}
export function shortOrderReference(order,orders=[]){
  const id=String(order?.id??order??''),code=uuid(id);if(!code)return id||'—';
  const others=orders.filter(o=>String(o?.id??o)!==id).map(o=>uuid(o?.id??o)).filter(Boolean);
  let length=8;while(length<32&&others.some(other=>other.slice(0,length)===code.slice(0,length)))length+=2;
  return 'FT-'+code.slice(0,length);
}
export function buildOrderReferences(orders){
  const result=new Map(),rows=[];
  for(const order of orders){const id=String(order.id),code=uuid(id);if(code)rows.push({id,code});else result.set(id,id);}
  rows.sort((a,b)=>a.code.localeCompare(b.code));
  rows.forEach((row,index)=>{let length=8;const before=rows[index-1]?.code,after=rows[index+1]?.code;while(length<32&&[before,after].some(other=>other?.startsWith(row.code.slice(0,length))))length+=2;result.set(row.id,[before,after].includes(row.code)?row.id:'FT-'+row.code.slice(0,length));});
  return result;
}
export function orderNeedsAttention(order,now=Date.now()){
  if(!order||order.adminHiddenAt)return false;
  if(order.paymentStatus==='review'||order.fulfillmentStatus==='review')return true;
  if(['cancelled','expired'].includes(order.fulfillmentStatus))return false;
  if(order.fulfillmentStatus==='delivered')return order.paymentStatus!=='confirmed';
  if(order.fulfillmentStatus==='reserved'&&Number(order.reservationExpiresAt)>0&&Number(order.reservationExpiresAt)<=now)return false;
  if(order.fulfillmentStatus)return true;
  return !['مكتمل','ملغي'].includes(order.status);
}

// Never resolve historical sales against today's mutable catalog.
export function presentOrder(order,locale='ar'){
  const saved=order?.saleSnapshot;
  if(!saved||saved.version!==1)return {...order};
  const ar=saved.translations?.ar||{},row=saved.translations?.[locale]||{};
  return {...order,name:row.name||ar.name||saved.name,nameLocalized:true,unit:saved.unit,
    denominationName:row.denominationName||ar.denominationName||saved.denominationName||'',
    denominationDescription:row.denominationDescription||ar.denominationDescription||saved.denominationDescription||''};
}
export function orderDenomination(order,format=String){
  const saved=order?.saleSnapshot;
  const title=order?.offerLabel||saved?.serviceTitle||order?.denominationName||saved?.denominationName;
  if(title)return title;
  const value=saved?.denominationValue??order?.value;
  if(order?.digitalProvider||saved?.source==='api')return Number(value)>0?format(value):'خدمة رقمية';
  return value===null||value===undefined?'—':format(value);
}
