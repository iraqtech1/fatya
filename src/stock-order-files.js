import {connected,readStockUpload} from './api.js';
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
function expiryCell(value,{dateFormat='DMY',twoDigitPivot=null,monthEnd=false}={}){
  if(value?.kind==='date')return value.iso;
  if(value?.kind==='number'){
    const n=value.value,day=Math.floor(n);
    if(!Number.isFinite(n)||n<0||(!value.date1904&&day<1)||day>2958465||(!value.date1904&&day===60))throw Error('رقم تاريخ Excel غير صحيح');
    const epoch=value.date1904?Date.UTC(1904,0,1):Date.UTC(1899,11,day<60?31:30);
    return isoDate(new Date(epoch+day*86400000));
  }
  const s=String(value??'').trim();if(!s)return '';
  if(/^(?:no\s*expiry|without\s*expiry|بدون\s*انتهاء)$/i.test(s))return null;
  const year=y=>{if(String(y).length===4)return +y;if(twoDigitPivot===null||twoDigitPivot===''||!Number.isInteger(Number(twoDigitPivot))||Number(twoDigitPivot)<0||Number(twoDigitPivot)>99)throw Error('حدد حد السنة ذات الرقمين قبل الاستيراد');return +y>=Number(twoDigitPivot)?1900+(+y):2000+(+y);};
  let m=s.match(/^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})$/)||s.match(/^(\d{4})(\d{2})(\d{2})$/);if(m)return calendar(+m[1],+m[2],+m[3]);
  m=s.match(/^(\d{1,2})[-/.](\d{1,2})[-/.](\d{2}|\d{4})$/);
  if(m){let order=dateFormat;if(order==='auto'){if(+m[1]>12&&+m[2]<=12)order='DMY';else if(+m[2]>12&&+m[1]<=12)order='MDY';else if(+m[1]===+m[2])order='DMY';else throw Error('تاريخ ملتبس؛ حدد يوم/شهر أو شهر/يوم');}if(!['DMY','MDY'].includes(order))throw Error('اختر ترتيب التاريخ في الملف');return calendar(year(m[3]),+(order==='MDY'?m[1]:m[2]),+(order==='MDY'?m[2]:m[1]));}
  m=s.match(/^(\d{1,2})\/(\d{2}|\d{4})$/);
  if(m){if(!monthEnd)throw Error('تاريخ شهر وسنة فقط؛ اختر اعتماد آخر يوم من الشهر');const y=year(m[2]),month=+m[1];if(month<1||month>12)throw Error('شهر غير صحيح');return calendar(y,month,new Date(Date.UTC(y,month,0)).getUTCDate());}
  const months=['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'];
  m=s.match(/^(\d{1,2})\s+([a-z]{3,9})\s+(\d{4})$/i);if(m&&months.includes(m[2].slice(0,3).toLowerCase()))return calendar(+m[3],months.indexOf(m[2].slice(0,3).toLowerCase())+1,+m[1]);
  m=s.match(/^([a-z]{3,9})\s+(\d{1,2}),?\s+(\d{4})$/i);if(m&&months.includes(m[1].slice(0,3).toLowerCase()))return calendar(+m[3],months.indexOf(m[1].slice(0,3).toLowerCase())+1,+m[2]);
  throw Error('صيغة تاريخ الانتهاء غير مدعومة؛ اختر ترتيب التاريخ أو استخدم YYYY-MM-DD');
}
export async function readStockFile(file){
  return (await readStockSheets(file))[0].raw;
}
export async function readStockSheets(file){
  if(file.size>15000000)throw Error('الحد الأقصى للملف 15 MB');
  if(connected)return readStockUpload(file);
  if(/\.(txt|csv|tsv)$/i.test(file.name))return [{name:file.name,raw:parseStockText((await file.text()).replace(/^\uFEFF/,''))}];
  if(!/\.xlsx$/i.test(file.name))throw Error('الصيغ المدعومة TXT وCSV وTSV وXLSX؛ حوّل XLS إلى XLSX');
  const {default:Excel}=await import('exceljs'),book=new Excel.Workbook();await book.xlsx.load(await file.arrayBuffer());
  if(!book.worksheets.length)throw Error('الملف لا يحتوي ورقة بيانات');if(book.worksheets.length>200)throw Error('الحد الأقصى 200 ملف أو ورقة');
  const sheets=[];let total=0;
  for(const sheet of book.worksheets){if(sheet.rowCount>MAX_STOCK_ROWS+1||sheet.columnCount>50)throw Error('تجاوز الملف حد الصفوف أو الأعمدة');
  const rows=[];sheet.eachRow(row=>{const data=[];for(let i=1;i<=sheet.columnCount;i++){
    const cell=row.getCell(i),v=cell.value;
    if(v instanceof Date)data.push({kind:'date',iso:isoDate(v)});
    else if(typeof v==='number')data.push({kind:'number',value:v,format:cell.numFmt||'General',date1904:!!book.properties.date1904});
    else if(v!==null&&typeof v==='object')throw Error('لا تقبل المعادلات أو الخلايا المركبة؛ استخدم نصوصاً أو تواريخ Excel');
    else data.push(v===null?'':String(v));
  }if(data.some(v=>stockCellLabel(v).trim()))rows.push(data);});if(rows.length){total+=Math.max(0,rows.length-1);sheets.push({name:file.name+' / '+sheet.name,raw:rows});}}
  if(total>MAX_STOCK_ROWS)throw Error('الحد الأقصى 50000 بطاقة');if(!sheets.length)throw Error('الملف فارغ');return sheets;
}
export function stockRows(raw,{skipHeader=true,pinColumn=0,serialColumn=1,expiryColumn=2,cvcColumn=-1,referenceColumn=-1,tolerantExpiry=false,expiry='',sourceRows,dateFormat='DMY',twoDigitPivot=null,monthEnd=false}){
  if(!Number.isInteger(pinColumn)||pinColumn<0||!Number.isInteger(serialColumn)||serialColumn< -1||!Number.isInteger(expiryColumn)||expiryColumn< -1)throw Error('اختر أعمدة صحيحة');
  const selected=[pinColumn,serialColumn,expiryColumn,cvcColumn,referenceColumn];if(selected.some(i=>!Number.isInteger(i)||i< -1))throw Error('اختر أعمدة صحيحة');if(new Set(selected.filter(i=>i>=0)).size!==selected.filter(i=>i>=0).length)throw Error('اختر عموداً مختلفاً لكل حقل');
  return raw.slice(skipHeader?1:0).map((row,i)=>{
    const sourceRow=sourceRows?.[i+(skipHeader?1:0)]??i+(skipHeader?2:1);
    try{let date='',warning='',expiryError='';try{const v=row[expiryColumn],m=tolerantExpiry&&dateFormat==='DMY'&&typeof v==='string'&&v.match(/^(\d{1,2})\/(\d{1,2})\/\d{4}$/);if(m&&+m[1]>0&&+m[1]<=12&&+m[2]>0&&+m[2]<=12&&+m[1]!==+m[2])throw Error('تاريخ ملتبس بين اليوم والشهر؛ استخدم الانتهاء اليدوي أو YYYY-MM-DD');date=expiryColumn<0?'':expiryCell(v,{dateFormat,twoDigitPivot,monthEnd});}catch(e){if(!tolerantExpiry)throw e;warning=e.message;if(dateFormat==='auto'&&!expiry)expiryError=e.message;}return {sourceRow,pin:codeCell(row[pinColumn]),serial:serialColumn<0?'':codeCell(row[serialColumn]),expiry:date===null?'':date||expiry,...date===null?{noExpiry:true}:{},...expiryError?{expiryError}:{},...(cvcColumn>=0?{cvc:codeCell(row[cvcColumn])}:{}),...(referenceColumn>=0?{reference:codeCell(row[referenceColumn])}:{}),...warning?{expiryWarning:warning}:{}};}
    catch(e){throw Error('صف '+sourceRow+': '+e.message);}
  });
}

export {codeCell as stockCodeCell,expiryCell as stockExpiryCell};
