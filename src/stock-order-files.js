export const MAX_STOCK_ROWS=50000;
export function parseStockText(text){
  const first=text.split(/\r?\n/,1)[0]||'',delimiter=first.includes('\t')?'\t':first.includes(';')?';':',';
  const rows=[];let row=[],cell='',quoted=false;
  for(let i=0;i<text.length;i++){const ch=text[i];if(ch==='"'){if(quoted&&text[i+1]==='"'){cell+='"';i++;}else if(!cell||quoted)quoted=!quoted;else cell+=ch;}else if(ch===delimiter&&!quoted){row.push(cell);cell='';}else if((ch==='\n'||ch==='\r')&&!quoted){if(ch==='\r'&&text[i+1]==='\n')i++;row.push(cell);if(row.some(v=>v.trim()))rows.push(row);row=[];cell='';if(rows.length>MAX_STOCK_ROWS+1)throw Error('الحد الأقصى 50000 بطاقة');}else cell+=ch;}
  if(quoted)throw Error('علامات الاقتباس غير مكتملة في الملف');row.push(cell);if(row.some(v=>v.trim()))rows.push(row);if(rows.length>MAX_STOCK_ROWS+1)throw Error('الحد الأقصى 50000 بطاقة');return rows;
}
function isoDate(value){
  if(!(value instanceof Date)||!Number.isFinite(value.getTime()))throw Error('تاريخ غير صحيح');
  if(value.getUTCFullYear()<1900||value.getUTCFullYear()>9999)throw Error('سنة الانتهاء غير صحيحة');
  return value.toISOString().slice(0,10);
}
function calendar(year,month,day){
  const date=new Date(Date.UTC(year,month-1,day));
  if(date.getUTCFullYear()!==year||date.getUTCMonth()!==month-1||date.getUTCDate()!==day)throw Error('تاريخ الانتهاء غير صحيح');
  return isoDate(date);
}
export function stockCellLabel(value){
  if(value?.kind==='date')return value.iso;
  if(value?.kind==='number')return String(value.value);
  return String(value??'');
}
function codeCell(value){
  if(value?.kind==='date')throw Error('عمود PIN أو السيريال يحتوي تاريخاً؛ اختر الأعمدة الصحيحة');
  if(value?.kind==='number'){
    const n=value.value,format=value.format||'General';
    if(!Number.isSafeInteger(n)||n<0||n>=1e15)throw Error('PIN والسيريال الرقمي يجب أن يكونا أقل من 16 خانة؛ احفظ الأكواد الطويلة كنص في الملف الأصلي');
    if(/^0{1,256}$/.test(format))return String(n).padStart(format.length,'0');
    if(format==='General'||format==='@')return String(n);
    throw Error('تنسيق رقم PIN أو السيريال غير مدعوم؛ احفظه كنص أو استخدم تنسيق أصفار فقط');
  }
  return String(value??'').trim();
}
function expiryCell(value){
  if(value?.kind==='date')return value.iso;
  if(value?.kind==='number'){
    const n=value.value,day=Math.floor(n);
    if(!Number.isFinite(n)||n<0||(!value.date1904&&day<1)||day>2958465||(!value.date1904&&day===60))throw Error('رقم تاريخ Excel غير صحيح');
    const epoch=value.date1904?Date.UTC(1904,0,1):Date.UTC(1899,11,day<60?31:30);
    return isoDate(new Date(epoch+day*86400000));
  }
  const s=String(value??'').trim();if(!s)return '';
  let m=s.match(/^(\d{4})-(\d{2})-(\d{2})$/);if(m)return calendar(+m[1],+m[2],+m[3]);
  m=s.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);if(m)return calendar(+m[3],+m[2],+m[1]);
  throw Error('اكتب تاريخ الانتهاء YYYY-MM-DD أو يوم/شهر/سنة');
}
export async function readStockFile(file){
  if(file.size>15000000)throw Error('الحد الأقصى للملف 15 MB');
  if(/\.(txt|csv|tsv)$/i.test(file.name))return parseStockText((await file.text()).replace(/^\uFEFF/,''));
  if(!/\.xlsx$/i.test(file.name))throw Error('الصيغ المدعومة TXT وCSV وTSV وXLSX؛ حوّل XLS إلى XLSX');
  const {default:Excel}=await import('exceljs'),book=new Excel.Workbook();await book.xlsx.load(await file.arrayBuffer());
  const sheet=book.worksheets[0];if(!sheet)throw Error('الملف لا يحتوي ورقة بيانات');if(sheet.rowCount>MAX_STOCK_ROWS+1||sheet.columnCount>50)throw Error('تجاوز الملف حد الصفوف أو الأعمدة');
  const rows=[];sheet.eachRow(row=>{const data=[];for(let i=1;i<=sheet.columnCount;i++){
    const cell=row.getCell(i),v=cell.value;
    if(v instanceof Date)data.push({kind:'date',iso:isoDate(v)});
    else if(typeof v==='number')data.push({kind:'number',value:v,format:cell.numFmt||'General',date1904:!!book.properties.date1904});
    else if(v!==null&&typeof v==='object')throw Error('لا تقبل المعادلات أو الخلايا المركبة؛ استخدم نصوصاً أو تواريخ Excel');
    else data.push(v===null?'':String(v));
  }if(data.some(v=>stockCellLabel(v).trim()))rows.push(data);});return rows;
}
export function stockRows(raw,{skipHeader=true,pinColumn=0,serialColumn=1,expiryColumn=2}){
  if(!Number.isInteger(pinColumn)||pinColumn<0||!Number.isInteger(serialColumn)||serialColumn< -1||!Number.isInteger(expiryColumn)||expiryColumn< -1)throw Error('اختر أعمدة صحيحة');
  if(pinColumn===serialColumn||pinColumn===expiryColumn||serialColumn>=0&&serialColumn===expiryColumn)throw Error('اختر عموداً مختلفاً لكل حقل');
  return raw.slice(skipHeader?1:0).map((row,i)=>{
    const sourceRow=i+(skipHeader?2:1);
    try{return {sourceRow,pin:codeCell(row[pinColumn]),serial:serialColumn<0?'':codeCell(row[serialColumn]),expiry:expiryColumn<0?'':expiryCell(row[expiryColumn])};}
    catch(e){throw Error('صف '+sourceRow+': '+e.message);}
  });
}
