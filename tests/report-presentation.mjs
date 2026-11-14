import assert from 'node:assert/strict';
import {decimal,formatReportValue,reportColumns,reportStatuses,reportSummary,enrichReportRows,reportExportValue} from '../src/report-presentation.js';
assert.equal(decimal('99999999999999999.120'),'99,999,999,999,999,999.12');
assert.equal(formatReportValue('cost','0.000'),'0 د.ع');assert.equal(formatReportValue('cost',null),'غير معروف');
assert.equal(formatReportValue('amount','10.001','USD'),'10.001 USD');
assert.equal(formatReportValue('details','{"quantity":50,"costTotal":"250000.000","filters":{"status":"available"}}'),'الكمية: 50؛ إجمالي التكلفة: 250,000 د.ع؛ الفلاتر: الحالة: متاح');
for(const type of ['inventory','movements','procurement','sales','digital','payments','exports','audit'])assert.ok(reportColumns(type).length<=8);
assert.ok(reportStatuses.payments.includes('processing'));assert.ok(reportStatuses.payments.includes('delivered'));
const rows=enrichReportRows([{id:'order',productId:'2',amount:'100',reason:null},{id:'other',extra:'present'}],[{id:2,name:'اسم قديم',manufacturerId:'company'}],[{id:'company',name:'آسياسيل'}]);assert.equal(rows[0].company,'آسياسيل');
assert.ok(reportColumns('sales',rows,true).includes('extra'),'Exports include keys absent from the first row');
assert.equal(reportSummary('sales',{rows:3,amount:'999',deliveredAmount:'100',deliveredUnknownCost:1}).find(t=>t.key==='deliveredAmount').value,'100 د.ع');assert.ok(!reportSummary('sales',{amount:'999'}).some(t=>t.key==='amount'));
console.log('Reports preserve decimal precision, currencies, missing costs and nested details; tables and full exports share Arabic formatting.');

assert.ok(reportColumns('inventory').includes('id'));assert.ok(reportColumns('inventory').includes('serial'),'Per-card inventory rows need distinct numbers and original serials');

assert.equal(reportExportValue('amount','5250.001'),5250.001);
assert.equal(reportExportValue('cost',null),'غير معروف');
assert.equal(reportExportValue('actualCost','0.000'),0);
assert.equal(reportExportValue('reference','0001234567890123456789'),'0001234567890123456789');
assert.equal(reportExportValue('amount','99999999999999999.123'),'99999999999999999.123','Amounts beyond Excel precision stay exact text');
assert.equal(enrichReportRows([{company:'Frozen company',productId:'2'}],[{id:2,name:'New name',manufacturerId:'new'}],[{id:'new',name:'New company'}])[0].company,'Frozen company');
