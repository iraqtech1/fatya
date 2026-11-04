import {useViewHistory} from '../src/view-history.js';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createRequire} from 'node:module';
import * as Vue from 'vue/dist/vue.esm-bundler.js';
import {useReportFlow} from '../src/report-flow.js';
import {useStockOrderPreview} from '../src/stock-order-preview.js';
import {importPolicy,importFieldOptions} from '../src/product-metadata.js';
import {withBuiltInImportMappings,resolveImportDraft} from '../src/asiacell-import-mappings.js';
import {stockRows,stockCellLabel,parseStockText} from '../src/stock-order-files.js';
import {detectStockFormat} from '../src/stock-import-formats.js';
const require=createRequire(import.meta.url),{renderToString}=require('@vue/server-renderer'),icons=require('lucide-vue-next');
function component(file,dependencies){const src=readFileSync(new URL('../src/'+file,import.meta.url),'utf8').replace(/^import .*;\r?\n/gm,'').replace('export default','return');dependencies={useViewHistory,...dependencies};const entries=Object.entries(dependencies).filter(([key])=>/^[A-Za-z_$][A-Za-z0-9_$]*$/.test(key));return Function(...entries.map(([k])=>k),src)(...entries.map(([,v])=>v));}
async function render(c,context){const warnings=[],app=Vue.createSSRApp({...c,setup:()=>context});app.directive('permit',{getSSRProps:()=>({})});app.config.warnHandler=m=>warnings.push(m);const html=await renderToString(app);assert.deepEqual(warnings,[]);return html;}
const report=component('ReportsCenter.js',{...Vue,...icons,useReportFlow,can:()=>true,connected:true,api:async()=>({rows:[{id:'0001',status:'available'}],totals:{rows:1},page:1,pages:1}),store:{products:[],companies:[]},exportWorkbook:async()=>{}});
let r=report.setup();assert.ok((await render(report,r)).includes('report-card'));
await r.open(r.cards.value[0]);await r.open(r.visibleCards.value[0]);r.tablesOpen.value=true;
const reportHtml=await render(report,r);assert.ok(reportHtml.includes('الفلترة والجداول'));assert.ok(reportHtml.includes('0001'));assert.ok(reportHtml.includes('aria-expanded="true"'));
const scope=Vue.effectScope();
await scope.run(async()=>{
 const requests=[],store={products:[],companies:[],orders:[]},connection=Vue.reactive({revision:1});let fail=false;
 const stock=component('StockOrdersManager.js',{...Vue,onMounted:()=>{},can:()=>true,importPolicy,importFieldOptions,store,connection,refreshStore:async()=>{},useStockOrderPreview,withBuiltInImportMappings,resolveImportDraft,stockRows,stockCellLabel,parseStockText,detectStockFormat,readStockSheets:()=>{},PagedList:{props:['items'],template:'<div><slot :items="items"/></div>'},connected:true,api:async(path,body)=>{requests.push({path,body});if(fail)throw Error('Preview failed');return {previewId:'p1',previewHash:'hash',quantity:1,rejected:0,costTotal:'100',retailTotal:'120',lines:[],errors:[],warnings:[]};}});
 const c=stock.setup();assert.equal(c.canStep(2),false);c.next();assert.equal(c.phase.value,1);assert.match(c.error.value,/المجهز/);
 c.supplier.value='Supplier';c.source.value='Invoice';c.next();assert.equal(c.phase.value,2);assert.equal(c.lines.value.length,1);
 const line=c.lines.value[0];line.raw=Vue.markRaw([['PIN'],['0001']]);
 c.goStep(1);c.goStep(2);assert.equal(c.lines.value[0].raw[1][0],'0001','Returning through tabs retains uploaded rows');
 c.goStep(3);assert.equal(c.phase.value,2,'Cannot skip server preview');
 fail=true;await c.check();assert.equal(c.phase.value,2);assert.equal(c.preview.value,null);assert.equal(c.error.value,'Preview failed');fail=false;
 await c.check();assert.equal(c.phase.value,3);assert.ok(c.preview.value);assert.equal(requests.at(-1).path,'/admin/procurement/stock-orders/preview');
 const html=await render(stock,c);assert.equal((html.match(/role="tab"/g)||[]).length,5);assert.ok(html.includes('id="stock-order-panel-3"'));assert.ok(html.includes('aria-selected="true"'));
 c.switchTab('history');assert.equal(c.activeTab.value,'history');assert.equal(c.phase.value,3);assert.ok(c.preview.value);
 c.batches.value=[{id:'a',status:'approved',supplier:'Approved supplier',source:'Invoice',lines:[{line:1,name:'Approved card',value:1000,fileName:'approved.xlsx',accepted:2,cost:'900',retail:'1100',costTotal:'1800'}]},{id:'p',status:'pending'},{id:'r',status:'rejected'}];assert.deepEqual(c.approvedBatches.value.map(b=>b.id),['a']);
 const historyHtml=await render(stock,c);assert.ok(historyHtml.includes('سجل الطلبيات المعتمدة'));assert.ok(historyHtml.includes('approved.xlsx'));assert.ok(historyHtml.includes('Approved card'));assert.ok(historyHtml.includes('عرض تفاصيل الطلبية'));assert.ok(historyHtml.includes('id="stock-section-panel-new"'));
 c.switchTab('new');assert.equal(c.lines.value[0].raw[1][0],'0001');assert.equal(c.phase.value,3);
 c.goStep(2);assert.ok(c.preview.value,'Returning without edits preserves a valid preview');c.goStep(3);line.cost='200';assert.equal(c.preview.value,null);assert.equal(c.phase.value,2,'Invalid preview cannot remain on approval tab');assert.equal(c.canStep(3),false);
 c.busy.value=true;c.switchTab('history');assert.equal(c.activeTab.value,'new');c.goStep(1);assert.equal(c.phase.value,2);c.busy.value=false;
});
scope.stop();
console.log('Reports and stock-order tabs render without missing bindings; files persist and server preview gates approval after edits.');
