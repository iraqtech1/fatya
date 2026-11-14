import {stockCodeCell,stockExpiryCell} from './stock-order-files.js';
import {connected,readStockUpload} from './api.js';
async function workbook(){const mod=await import('exceljs');return new mod.default.Workbook();}
export async function readInventory(file){
  if(!/\.xlsx$/i.test(file.name))throw Error("اختار ملف Excel بصيغة XLSX");
  if(file.size>2*1024*1024)throw Error("حجم الملف لازم يكون أقل من 2 MB");
  if(connected){
    const sheets=await readStockUpload(file);if(sheets.length!==1)throw Error('لملف متعدد الأوراق استخدم رفع الطلبيات');
    const raw=sheets[0].raw;if(raw.length>5001)throw Error('الحد الأقصى 5000 بطاقة في الاستيراد البسيط');
    const aliases={pin:'code',pincode:'code',cvv:'cvc',expirydate:'expiry',serialnumber:'serial',referencenumber:'reference'};
    const headers=raw[0].map(v=>{const h=String(v).trim().toLowerCase();return aliases[h]||h;});
    if(new Set(headers.filter(Boolean)).size!==headers.filter(Boolean).length||!['product_id','denomination','code'].every(h=>headers.includes(h)))throw Error('استخدم أعمدة القالب دون تكرار: product_id denomination code');
    return raw.slice(1).map(r=>Object.fromEntries(headers.filter(Boolean).map(h=>[h,r[headers.indexOf(h)]??''])));
  }
  const book=await workbook();await book.xlsx.load(await file.arrayBuffer());
  const sheet=book.worksheets[0];if(!sheet)throw Error("الملف ما بيه ورقة بيانات");
  if(sheet.rowCount>5001)throw Error("الحد الأقصى 5000 كارت بكل ملف");
  const headerAliases={pin:'code',pincode:'code',cvv:'cvc',expirydate:'expiry',serialnumber:'serial',referencenumber:'reference'};const headers=sheet.getRow(1).values.slice(1).map(v=>{const h=String(v??'').trim().toLowerCase();return headerAliases[h]||h;});if(new Set(headers.filter(Boolean)).size!==headers.filter(Boolean).length)throw Error('يوجد عمود مكرر في الملف');
  if(!['product_id','denomination','code'].every(h=>headers.includes(h)))throw Error("استخدم أعمدة القالب: product_id denomination code");
  const rows=[];for(let i=2;i<=sheet.rowCount;i++){const row=sheet.getRow(i);if(!row.hasValues)continue;const item={};for(const header of ['product_id','denomination','code',...['serial','expiry','cvc','reference'].filter(h=>headers.includes(h))]){const cell=row.getCell(headers.indexOf(header)+1),v=cell.value;const typed=v instanceof Date?{kind:'date',iso:v.toISOString().slice(0,10)}:typeof v==='number'?{kind:'number',value:v,format:cell.numFmt||'General',date1904:!!book.properties.date1904}:v;if(v!==null&&typeof v==='object'&&!(v instanceof Date))throw Error('الخلايا لازم تكون قيم نصية أو أرقام بدون معادلات');item[header]=header==='expiry'?stockExpiryCell(typed):['code','serial','cvc','reference'].includes(header)?stockCodeCell(typed):v;}rows.push(item);}return rows;
}
export async function exportWorkbook(name,headers,rows,options={}){
  const book=await workbook(),sheet=book.addWorksheet('Fatya');sheet.views=[{rightToLeft:true}];sheet.addRow(headers);rows.forEach(r=>sheet.addRow(r));sheet.columns.forEach(c=>c.width=24);sheet.getRow(1).font={bold:true,color:{argb:'FFFFFFFF'}};sheet.getRow(1).fill={type:'pattern',pattern:'solid',fgColor:{argb:'FF034037'}};
  if(options.columns)options.columns.forEach((format,index)=>{sheet.getColumn(index+1).numFmt=format;});
  if(options.metadata?.length){const metadata=book.addWorksheet('بيانات التقرير');metadata.views=[{rightToLeft:true}];options.metadata.forEach(row=>metadata.addRow(row));metadata.columns.forEach(column=>column.width=38);}
  const blob=new Blob([await book.xlsx.writeBuffer()],{type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}),url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download=name;a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
}
