async function workbook(){const mod=await import('exceljs');return new mod.default.Workbook();}
export async function readInventory(file){
  if(!/\.xlsx$/i.test(file.name))throw Error('اختار ملف Excel بصيغة XLSX.');
  if(file.size>2*1024*1024)throw Error('حجم الملف لازم يكون أقل من 2 MB.');
  const book=await workbook();await book.xlsx.load(await file.arrayBuffer());
  const sheet=book.worksheets[0];if(!sheet)throw Error('الملف ما بيه ورقة بيانات.');
  if(sheet.rowCount>5001)throw Error('الحد الأقصى 5000 كارت بكل ملف.');
  const headers=sheet.getRow(1).values.slice(1).map(v=>String(v??'').trim());
  if(!['product_id','denomination','code'].every(h=>headers.includes(h)))throw Error('استخدم أعمدة القالب: product_id, denomination, code.');
  const rows=[];for(let i=2;i<=sheet.rowCount;i++){const row=sheet.getRow(i);if(!row.hasValues)continue;const item={};for(const header of ['product_id','denomination','code',...(headers.includes('serial')?['serial']:[])]){const v=row.getCell(headers.indexOf(header)+1).value;if(typeof v==='object'&&v!==null)throw Error('الخلايا لازم تكون قيم نصية أو أرقام بدون معادلات.');item[header]=v;}rows.push(item);}return rows;
}
export async function exportWorkbook(name,headers,rows){
  const book=await workbook(),sheet=book.addWorksheet('Fatya');sheet.views=[{rightToLeft:true}];sheet.addRow(headers);rows.forEach(r=>sheet.addRow(r));sheet.columns.forEach(c=>c.width=24);sheet.getRow(1).font={bold:true,color:{argb:'FFFFFFFF'}};sheet.getRow(1).fill={type:'pattern',pattern:'solid',fgColor:{argb:'FF9B4A66'}};
  const blob=new Blob([await book.xlsx.writeBuffer()],{type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}),url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download=name;a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
}
