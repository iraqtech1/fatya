import assert from 'node:assert/strict';
import {parseStockText,stockRows,readStockFile} from '../src/stock-order-files.js';
const raw=parseStockText('PIN,serial,expiry\r\n"0000123456789012","000001",\r\n"00002","a,b",2027-01-01');
assert.equal(raw[1][0],'0000123456789012');assert.equal(raw[2][1],'a,b');
assert.deepEqual(stockRows(raw,{}),[{sourceRow:2,pin:'0000123456789012',serial:'000001',expiry:''},{sourceRow:3,pin:'00002',serial:'a,b',expiry:'2027-01-01'}]);
assert.deepEqual(parseStockText('0001\t0002\t\n0003\t0004\t'),[['0001','0002',''],['0003','0004','']]);
assert.equal(stockRows([['0001']],{skipHeader:false,serialColumn:-1,expiryColumn:-1})[0].pin,'0001');
assert.throws(()=>parseStockText('"broken'),/اقتباس/);
assert.throws(()=>stockRows(raw,{pinColumn:1,serialColumn:1}),/مختلف/);
await assert.rejects(()=>readStockFile({name:'cards.xls',size:10}),/XLSX/);
await assert.rejects(()=>readStockFile({name:'cards.csv',size:15000001}),/15 MB/);
assert.equal((await readStockFile({name:'cards.csv',size:20,text:async()=> '\uFEFFpin,serial\n0001,0002'}))[0][0],'pin');
console.log('Procurement files: zero preservation, column mapping, quoted CSV/TSV, file limits and malformed input passed.');

const {default:Excel}=await import('exceljs');
async function xlsx(rows,formats={},date1904=false){
  const book=new Excel.Workbook();book.properties.date1904=date1904;
  const sheet=book.addWorksheet('Cards');rows.forEach(row=>sheet.addRow(row));
  for(const [address,format] of Object.entries(formats))sheet.getCell(address).numFmt=format;
  const bytes=await book.xlsx.writeBuffer();
  return readStockFile({name:'fixture.xlsx',size:bytes.byteLength,arrayBuffer:async()=>bytes});
}
let cells=await xlsx([['pin','serial','expiry'],[12345,1,new Date('2027-01-01T00:00:00Z')]],{A2:'0000000000',B2:'000000'});
assert.deepEqual(stockRows(cells,{}),[{sourceRow:2,pin:'0000012345',serial:'000001',expiry:'2027-01-01'}]);
cells=await xlsx([['pin','serial','expiry'],['000012345678901234567890',0,46388]]);
assert.equal(stockRows(cells,{})[0].expiry,'2027-01-01');assert.equal(stockRows(cells,{})[0].serial,'0');
cells=await xlsx([['pin','serial','expiry'],['0001','0002',new Date('2027-01-01T00:00:00Z')]],{},true);
assert.equal(stockRows(cells,{})[0].expiry,'2027-01-01','1904 date system must round-trip');
cells=await xlsx([['pin','serial','expiry'],['0001','0002',0]],{},true);
assert.equal(stockRows(cells,{})[0].expiry,'1904-01-01');
for(const value of [1234567890123456,1.25,-1]){cells=await xlsx([['pin','serial','expiry'],[value,'','']]);assert.throws(()=>stockRows(cells,{}),/صف 2/);}
cells=await xlsx([['pin','serial','expiry'],[123,'','']],{A2:'0.00E+00'});assert.throws(()=>stockRows(cells,{}),/تنسيق/);
cells=await xlsx([['pin','serial','expiry'],[new Date('2027-01-01T00:00:00Z'),'','']]);assert.throws(()=>stockRows(cells,{}),/يحتوي تاريخ/);
await assert.rejects(()=>xlsx([['pin','serial','expiry'],[{formula:'1+1',result:2},'','']]),/المعادلات/);
assert.equal(stockRows([['0001','','31/12/2027']],{skipHeader:false})[0].expiry,'2027-12-31');
assert.equal(stockRows([['0001','','29/02/2028']],{skipHeader:false})[0].expiry,'2028-02-29');
for(const value of ['2027-02-29','31/02/2027','12/31/2027','not a date'])assert.throws(()=>stockRows([['0001','',value]],{skipHeader:false}),/صف 1/);
assert.throws(()=>stockRows([['0001','',{kind:'number',value:60}]],{skipHeader:false}),/Excel/);
console.log('XLSX zero masks, long text identifiers, native/serial dates, 1904 epoch, leap days and rejection of unsafe codes/formulas passed.');

const parsedExpiry=(text,options={})=>stockRows([['pin','','expiry'],['0001','',text]],{dateFormat:'auto',...options})[0];
for(const value of ['2028/02/29','2028.02.29','20280229','29-02-2028','29.02.2028','29 Feb 2028','Feb 29 2028'])assert.equal(parsedExpiry(value).expiry,'2028-02-29');
assert.equal(parsedExpiry('12/31/2027').expiry,'2027-12-31');
assert.throws(()=>parsedExpiry('01/02/2027'),/ملتبس/);
assert.equal(parsedExpiry('01/02/2027',{dateFormat:'MDY'}).expiry,'2027-01-02');
assert.equal(parsedExpiry('01/02/2027',{dateFormat:'DMY'}).expiry,'2027-02-01');
assert.throws(()=>parsedExpiry('31/12/27'),/الرقمين/);
assert.equal(parsedExpiry('31/12/27',{twoDigitPivot:50}).expiry,'2027-12-31');
assert.throws(()=>parsedExpiry('02/2028'),/آخر يوم/);
assert.equal(parsedExpiry('02/2028',{monthEnd:true}).expiry,'2028-02-29');
assert.equal(parsedExpiry('02/28',{monthEnd:true,twoDigitPivot:50}).expiry,'2028-02-29');
assert.equal(parsedExpiry('No Expiry',{expiry:'2030-01-01'}).expiry,'','Explicit no expiry overrides a default date');
assert.equal(parsedExpiry('',{expiry:'2030-01-01'}).expiry,'2030-01-01');

assert.equal(parsedExpiry('No Expiry',{expiry:'2030-01-01'}).noExpiry,true);
assert.ok(parsedExpiry('01/02/2027',{tolerantExpiry:true}).expiryError,'An ambiguous date is a rejected preview row unless an explicit manual default exists');
