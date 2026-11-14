import assert from 'node:assert/strict';
import Excel from 'exceljs';
import {exportWorkbook} from '../src/spreadsheets.js';
import {reportExportValue} from '../src/report-presentation.js';
let blob;
const create=URL.createObjectURL,revoke=URL.revokeObjectURL;
URL.createObjectURL=value=>{blob=value;return 'blob:report-fixture';};URL.revokeObjectURL=()=>{};
globalThis.document={createElement:()=>({click(){}})};
try{
 await exportWorkbook('fixture.xlsx',['مرجع الشركة','المبلغ','التكلفة'],[[reportExportValue('reference','0001234567890123456789'),reportExportValue('amount','5250.001'),reportExportValue('actualCost',null)]],{columns:['@','#,##0.###','#,##0.###'],metadata:[['المنطقة الزمنية','Asia/Baghdad'],['من تاريخ','2026-11-14T00:00:01']]});
 const book=new Excel.Workbook();await book.xlsx.load(await blob.arrayBuffer());
 assert.equal(book.worksheets[0].getCell('A2').value,'0001234567890123456789');
 assert.equal(book.worksheets[0].getCell('B2').value,5250.001);
 assert.equal(book.worksheets[0].getCell('C2').value,'غير معروف');
 assert.equal(book.worksheets[0].getCell('B2').numFmt,'#,##0.###');
 assert.equal(book.worksheets[1].getCell('B2').value,'2026-11-14T00:00:01');
 assert.equal(book.worksheets[0].getCell('A2').type,Excel.ValueType.String);
 console.log('Actual XLSX preserves long text references, numeric money, unknown costs and second-precise filter metadata.');
}finally{URL.createObjectURL=create;URL.revokeObjectURL=revoke;delete globalThis.document;}
