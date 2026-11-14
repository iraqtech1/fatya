import {parseStockText} from './stock-order-files.js';
export function priceChanges(raw,rows){
 if(!Array.isArray(raw)||raw.length<2||raw.length>1001)throw Error('ملف الأسعار يجب أن يحتوي من سطر إلى ألف سطر');
 const headers=raw[0].map(v=>String(v??'').trim()),keyIndex=headers.indexOf('key'),priceIndex=headers.indexOf('retail');
 if(keyIndex<0||priceIndex<0||new Set(headers).size!==headers.length)throw Error('أعمدة ملف الأسعار المطلوبة: key وretail');
 const current=new Map(rows.map(row=>[row.key,row])),changes={};
 for(const [i,cells] of raw.slice(1).entries()){
  const key=String(cells[keyIndex]??'').trim(),retail=String(cells[priceIndex]??'').trim(),row=current.get(key);
  if(!row||Object.hasOwn(changes,key)||!/^\d{1,16}(?:\.\d{1,3})?$/.test(retail))throw Error('سطر أسعار غير صحيح أو معرف مكرر: '+(i+2));
  for(const [header,field] of [['import_code','importCode'],['remote_id','remoteId']]){const index=headers.indexOf(header);if(index>=0&&String(cells[index]??'').trim()!==String(row[field]??''))throw Error('رمز الفئة أو معرف المزوّد لا يطابق المعرف المحلي في السطر '+(i+2));}
  changes[key]=retail;
 }
 return changes;
}
export async function readPrices(file,rows){
 if(file.size>2*1024*1024)throw Error('حد ملف الأسعار 2 ميغابايت');
 if(/\.(csv|txt)$/i.test(file.name))return priceChanges(parseStockText(await file.text()),rows);
 if(!/\.xlsx$/i.test(file.name))throw Error('اختر ملف XLSX أو CSV');
 const {default:ExcelJS}=await import('exceljs'),book=new ExcelJS.Workbook();await book.xlsx.load(await file.arrayBuffer());
 if(book.worksheets.length!==1&&book.worksheets[0]?.name!=='Fatya')throw Error('استخدم ورقة الأسعار الأولى من القالب');
 const sheet=book.worksheets[0];if(!sheet||sheet.rowCount>1001)throw Error('حد ملف الأسعار ألف سطر');
 const raw=[];sheet.eachRow(row=>{const cells=[];for(let index=1;index<=row.cellCount;index++){const value=row.getCell(index).value;if(value!==null&&typeof value==='object')throw Error('ملف الأسعار يقبل قيماً فقط دون معادلات أو روابط');cells.push(value);}raw.push(cells);});
 return priceChanges(raw,rows);
}
