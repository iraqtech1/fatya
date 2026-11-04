const dates=new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Baghdad',year:'numeric',month:'2-digit',day:'2-digit'});
export function stockOrderDate(value){
  const date=new Date(value);if(!value||Number.isNaN(date.getTime()))return '';
  const parts=Object.fromEntries(dates.formatToParts(date).map(p=>[p.type,p.value]));return parts.year+'-'+parts.month+'-'+parts.day;
}
export function filterStockOrders(rows,{from='',to='',supplier=''}={}){
  if(from&&to&&from>to)return [];
  return rows.filter(b=>{const day=stockOrderDate(b.createdAt);return b.status==='approved'&&(!supplier||b.supplier===supplier)&&(!(from||to)||!!day&&(!from||day>=from)&&(!to||day<=to));});
}
export const stockOrderHeaders=['رقم الطلبية','تاريخ الاعتماد (بغداد)','المجهّز','المصدر','المحافظة','عدد البطاقات','عدد الفئات','عدد الملفات','البطاقات المستبعدة','إجمالي التكلفة IQD','قيمة البيع عند المعاينة IQD','رُفعت بواسطة','الحالة'];
export function stockOrderExportRows(rows){return rows.map(b=>[b.id,stockOrderDate(b.createdAt),b.supplier,b.source,b.city||'',b.quantity,b.categoryCount??b.lines?.length??0,b.fileCount??0,b.rejected??0,b.costTotal,b.retailTotal??b.nominalTotal,b.actor||'','معتمدة']);}
