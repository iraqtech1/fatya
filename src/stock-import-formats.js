import {stockCellLabel} from './stock-order-files.js';
export const importCode=value=>String(value??'').trim().toUpperCase().replace(/^(EVS|EVD)-/,'');
const normal=v=>stockCellLabel(v).trim().toLowerCase().replace(/[\s_-]/g,'');
const aliases={pin:['pin','pincode','code','hrn','رمزالبطاقة','رمزالشحن'],serial:['serial','serialnumber','sn','سيريل','سيريال','الرقمالتسلسلي'],expiry:['expiry','expirydate','expiration','expiredate','تاريخالانتهاء'],category:['category','categorycode','productcode','رمزالفئة','معرفالفئة']};
export function detectStockFormat(raw){
  if(!raw.length)throw Error('الملف فارغ');const head=raw[0].map(stockCellLabel),columns={};
  for(const [key,names] of Object.entries(aliases))columns[key]=head.findIndex(v=>names.includes(normal(v)));
  const supplier=head.length>=5&&/^\d+$/.test(head[1])&&/^E[A-Z0-9-]+$/i.test(head[2]);
  if(supplier){
    const cards=raw.slice(1);if(Number(head[1])!==cards.length)throw Error('عدد بطاقات الملف لا يطابق رأس الملف');
    if(cards.some(r=>r.length<2||r.length>3))throw Error('ملف المجهّز يجب أن يحتوي سيريال وPIN وانتهاء اختياري');
    return {raw:[['Serial','PIN','Expiry'],...cards],skipHeader:true,pinColumn:1,serialColumn:0,expiryColumn:2,categoryCode:importCode(head[2]),format:'ملف مجهّز',tolerantExpiry:true};
  }
  if(columns.pin>=0){
    const codes=[...new Set(columns.category<0?[]:raw.slice(1).map(r=>importCode(r[columns.category])).filter(Boolean))];
    if(codes.length>1)throw Error('الورقة تحتوي أكثر من رمز فئة؛ افصل الفئات');
    return {raw,skipHeader:true,pinColumn:columns.pin,serialColumn:columns.serial,expiryColumn:columns.expiry,categoryCode:codes[0]||'',format:'أعمدة معروفة',tolerantExpiry:true};
  }
  if(raw.some(r=>/\bHRN\s+SN\b/i.test(r.map(stockCellLabel).join(' ')))){
    const codes=new Set(),cards=[];
    for(const row of raw){const m=row.map(stockCellLabel).join(' ').match(/\b([A-Z][A-Z0-9-]*)\s*:\s*HRN\s+SN\s+(\d+)\s+(\d+)\s*(.*)$/i);if(!m)throw Error('رسالة بطاقة غير مفهومة؛ حدد الأعمدة يدوياً');codes.add(importCode(m[1]));const date=m[4].match(/\d{1,2}[-/]\d{1,2}[-/]\d{4}/)?.[0]?.replaceAll('-','/')||'';cards.push([m[2],m[3],date]);}
    if(codes.size!==1)throw Error('الملف يحتوي أكثر من رمز فئة');
    return {raw:[['PIN','Serial','Expiry'],...cards],skipHeader:true,pinColumn:0,serialColumn:1,expiryColumn:2,categoryCode:[...codes][0],format:'رسائل HRN SN',tolerantExpiry:true};
  }
  return {raw,skipHeader:false,pinColumn:0,serialColumn:1,expiryColumn:-1,categoryCode:'',format:'حدد الأعمدة يدوياً',tolerantExpiry:true};
}
export function resolveImportCategory(code,mappings,products){
  const m=mappings.find(m=>m.code===importCode(code));if(!m)return null;
  const p=products.find(p=>String(p.id)===String(m.productId)),denom=p?.values.findIndex(v=>Number(v)===Number(m.value));
  return p&&denom>=0?{productId:p.id,denom}:null;
}
