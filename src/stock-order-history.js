import {reportExportValue} from './report-presentation.js';
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
