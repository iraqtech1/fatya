import {formatReportValue,reportExportValue} from './report-presentation.js';
export const stockHistoryColumns=[{key:'sequence',label:'التسلسل'},{key:'order',label:'الطلبية'},{key:'sent',label:'تاريخ الإرسال'},{key:'expiry',label:'انتهاء بطاقات الطلبية'},{key:'agent',label:'الوكيل'},{key:'categories',label:'الفئات'},{key:'company',label:'الشركة'},{key:'source',label:'المصدر'},{key:'files',label:'الملفات'},{key:'valid',label:'صالحة'},{key:'rejected',label:'مرفوضة'},{key:'cost',label:'إجمالي تكلفة الطلبية'},{key:'status',label:'الحالة'}];
export function stockHistoryExpiry(batch){const e=batch.cardExpiry;if(!e)return 'غير مسجل';if(!e.dated)return e.withoutDate?'بدون تاريخ انتهاء محفوظ':'غير مسجل';return (e.first===e.last?e.first:e.first+' — '+e.last)+(e.withoutDate?' · '+e.withoutDate+' بدون تاريخ':'');}
export function stockHistoryCell(key,batch,sequence,products=[],companies=[]){
 const lines=batch.lines||[];
 switch(key){
  case 'sequence':return String(sequence);
  case 'order':return batch.source||String(batch.id);
  case 'sent':return stockOrderDateTime(batch.submittedAt||batch.createdAt)||'غير مسجل';
  case 'expiry':return stockHistoryExpiry(batch);
  case 'agent':return batch.actor||'غير مسجل';
  case 'categories':return [...new Set(lines.map(l=>(l.name||products.find(p=>String(p.id)===String(l.productId))?.name||'فئة')+' · '+l.value))].join('، ')||String(batch.categoryCount??'غير مسجل');
  case 'company':return [...new Set(lines.map(l=>{const id=l.manufacturerId||products.find(p=>String(p.id)===String(l.productId))?.manufacturerId;return companies.find(c=>String(c.id)===String(id))?.name;}).filter(Boolean))].join('، ')||'غير منسوبة';
  case 'source':return batch.supplier||'غير مسجل';
  case 'files':return String(batch.fileCount??(lines.length?new Set(lines.map(l=>l.fileName).filter(Boolean)).size:'غير مسجل'));
  case 'valid':return String(batch.quantity??'غير مسجل');
  case 'rejected':return String(batch.rejected??'غير مسجل');
  case 'cost':return formatReportValue('costTotal',batch.costTotal);
  case 'status':return batch.status==='approved'?'معتمدة':batch.status||'غير مسجل';
  default:return '—';
 }
}
const dates=new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Baghdad',year:'numeric',month:'2-digit',day:'2-digit'});
export function stockOrderDate(value){
  const date=new Date(value);if(!value||Number.isNaN(date.getTime()))return '';
  const parts=Object.fromEntries(dates.formatToParts(date).map(p=>[p.type,p.value]));return parts.year+'-'+parts.month+'-'+parts.day;
}
export function filterStockOrders(rows,{from='',to='',supplier=''}={}){
  if(from&&to&&from>to)return [];
  return rows.filter(b=>{const day=stockOrderDate(b.createdAt);return b.status==='approved'&&(!supplier||b.supplier===supplier)&&(!(from||to)||!!day&&(!from||day>=from)&&(!to||day<=to));});
}
export function stockOrderDateTime(value){if(!value)return '';if(/^\d{4}-\d{2}-\d{2}$/.test(value))return value+' — الوقت غير مسجل';const date=new Date(value);if(Number.isNaN(date.getTime()))return '';const parts=Object.fromEntries(new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Baghdad',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23'}).formatToParts(date).map(part=>[part.type,part.value]));return `${parts.year}-${parts.month}-${parts.day} ${parts.hour}:${parts.minute}:${parts.second}`;}
export const stockOrderHeaders=['رقم الطلبية','تاريخ ووقت الاعتماد (بغداد)','المصدر','مرجع الطلبية','المحافظة','عدد البطاقات','عدد الفئات','عدد الملفات','البطاقات المستبعدة','إجمالي التكلفة IQD','قيمة البيع عند المعاينة IQD','رُفعت بواسطة','الحالة','ملاحظات الطلبية'];
export function stockOrderExportRows(rows){return rows.map(b=>[String(b.id),stockOrderDateTime(b.createdAt),b.supplier,b.source,b.city||'',b.quantity,b.categoryCount??b.lines?.length??0,b.fileCount??0,b.rejected??0,reportExportValue('costTotal',b.costTotal),reportExportValue('amount',b.retailTotal??b.nominalTotal),b.actor||'','معتمدة',b.notes||'']);}

function milliAmount(value){
  const match=String(value??'0').match(/^(\d+)(?:\.(\d{1,3}))?$/);return match?BigInt(match[1])*1000n+BigInt((match[2]||'').padEnd(3,'0')):0n;
}
function displayAmount(value){const whole=(value/1000n).toString().replace(/\B(?=(\d{3})+(?!\d))/g,','),fraction=(value%1000n).toString().padStart(3,'0').replace(/0+$/,'');return whole+(fraction?'.'+fraction:'');}
export function stockOrderTotals(rows){
  let quantity=0,cost=0n,retail=0n;for(const b of rows){quantity+=Number(b.quantity)||0;cost+=milliAmount(b.costTotal);retail+=milliAmount(b.retailTotal??b.nominalTotal);}
  return {orders:rows.length,quantity,cost:displayAmount(cost),retail:displayAmount(retail)};
}
