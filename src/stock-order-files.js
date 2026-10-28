export const MAX_STOCK_ROWS=50000;
export function parseStockText(text){
  const first=text.split(/\r?\n/,1)[0]||'',delimiter=first.includes('\t')?'\t':first.includes(';')?';':',';
  const rows=[];let row=[],cell='',quoted=false;
  for(let i=0;i<text.length;i++){const ch=text[i];if(ch==='"'){if(quoted&&text[i+1]==='"'){cell+='"';i++;}else if(!cell||quoted)quoted=!quoted;else cell+=ch;}else if(ch===delimiter&&!quoted){row.push(cell);cell='';}else if((ch==='\n'||ch==='\r')&&!quoted){if(ch==='\r'&&text[i+1]==='\n')i++;row.push(cell);if(row.some(v=>v.trim()))rows.push(row);row=[];cell='';if(rows.length>MAX_STOCK_ROWS+1)throw Error('الحد الأقصى 50000 بطاقة');}else cell+=ch;}
  if(quoted)throw Error('علامات الاقتباس غير مكتملة في الملف');row.push(cell);if(row.some(v=>v.trim()))rows.push(row);if(rows.length>MAX_STOCK_ROWS+1)throw Error('الحد الأقصى 50000 بطاقة');return rows;
}
export async function readStockFile(file){
  if(file.size>15000000)throw Error('الحد الأقصى للملف 15 MB');
  if(/\.(txt|csv|tsv)$/i.test(file.name))return parseStockText((await file.text()).replace(/^\uFEFF/,''));
  if(!/\.xlsx$/i.test(file.name))throw Error('الصيغ المدعومة TXT وCSV وTSV وXLSX؛ حوّل XLS إلى XLSX');
  const {default:Excel}=await import('exceljs'),book=new Excel.Workbook();await book.xlsx.load(await file.arrayBuffer());
  const sheet=book.worksheets[0];if(!sheet)throw Error('الملف لا يحتوي ورقة بيانات');if(sheet.rowCount>MAX_STOCK_ROWS+1||sheet.columnCount>50)throw Error('تجاوز الملف حد الصفوف أو الأعمدة');
  const rows=[];sheet.eachRow(row=>{const data=[];for(let i=1;i<=sheet.columnCount;i++){const cell=row.getCell(i),v=cell.value;if(v!==null&&typeof v==='object')throw Error('لا تقبل المعادلات أو الخلايا المركبة؛ استخدم نصوصاً');if(typeof v==='number'&&(!Number.isSafeInteger(v)||Math.abs(v)>=1e15))throw Error('احفظ PIN والسيريال كنص في Excel للحفاظ على الدقة');data.push(v===null?'':String(v));}if(data.some(v=>v.trim()))rows.push(data);});return rows;
}
export function stockRows(raw,{skipHeader=true,pinColumn=0,serialColumn=1,expiryColumn=2}){
  if(pinColumn===serialColumn||pinColumn===expiryColumn||serialColumn>=0&&serialColumn===expiryColumn)throw Error('اختر عموداً مختلفاً لكل حقل');
  return raw.slice(skipHeader?1:0).map((row,i)=>({sourceRow:i+(skipHeader?2:1),pin:String(row[pinColumn]??'').trim(),serial:serialColumn<0?'':String(row[serialColumn]??'').trim(),expiry:expiryColumn<0?'':String(row[expiryColumn]??'').trim()}));
}
