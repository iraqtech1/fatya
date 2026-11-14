import assert from 'node:assert/strict';
import {presentOrder,orderDenomination} from '../src/order-display.js';
import {readFileSync} from 'node:fs';
import {createSSRApp,computed,reactive} from 'vue';
import {renderToString} from '@vue/server-renderer';
import {buildOrderReferences,shortOrderReference,orderDateLabel,orderClock,orderCreatedLabel,orderNeedsAttention} from '../src/order-display.js';

const order={id:'ORDER-bd0ac19e-1c36-4858-91f3-dcd3d41a4f9b',createdAt:'2026-11-07T21:04:09Z',date:'2026-11-07'};
assert.equal(shortOrderReference(order),'FT-BD0AC19E');
assert.equal(orderDateLabel(order),'2026-11-08');
assert.equal(orderClock(order),'00:04:09');
assert.equal(orderCreatedLabel({date:'2026-10-19'}),'2026-10-19','Legacy dates must not acquire invented times');
for(const createdAt of [null,undefined,'2026-10-19','bad',0,NaN])assert.equal(orderClock({createdAt}),'');
assert.equal(orderClock({...order,createdAt:Date.parse(order.createdAt)}),'00:04:09');
const collision={id:'ORDER-bd0ac19e-2c36-4858-91f3-dcd3d41a4f9b'};
const refs=buildOrderReferences([order,collision,{id:'FATYA-123'}]);
assert.equal(refs.get(order.id),'FT-BD0AC19E1C');
assert.equal(refs.get(collision.id),'FT-BD0AC19E2C');
assert.equal(refs.get('FATYA-123'),'FATYA-123');
assert.deepEqual(buildOrderReferences([collision,order,{id:'FATYA-123'}]),refs,'Reference does not depend on sorting or pagination');
const now=Date.now();
const pending={id:'pending',paymentStatus:'pending',fulfillmentStatus:'reserved',reservationExpiresAt:now+60000};
const cases=[
 [pending,true],
 [{...pending,adminHiddenAt:'2026-11-08T00:00:00Z'},false],
 [{...pending,reservationExpiresAt:now-1},false],
 [{fulfillmentStatus:'cancelled',paymentStatus:'pending'},false],
 [{fulfillmentStatus:'expired',paymentStatus:'review'},true],
 [{fulfillmentStatus:'processing',paymentStatus:'confirmed'},true],
 [{fulfillmentStatus:'review',paymentStatus:'confirmed'},true],
 [{digitalProvider:'TOPUP',fulfillmentStatus:'delivered',paymentStatus:'confirmed',codesAvailable:false},false],
 [{status:'قيد المراجعة'},true],[{status:'مكتمل'},false],[{status:'ملغي'},false],
];
for(const [value,expected] of cases)assert.equal(orderNeedsAttention(value,now),expected,JSON.stringify(value));

// Render the actual sidebar binding and keep the count through section navigation.
const source=readFileSync(new URL('../src/Dashboard.js',import.meta.url),'utf8');
const sidebar=source.match(/<aside class="admin-sidebar"[\s\S]*?<\/aside>/)[0];
const rows=reactive([pending,{...pending,id:'review',paymentStatus:'review',fulfillmentStatus:'expired'}]);
const section=reactive({value:'overview'});
const count=computed(()=>rows.filter(o=>orderNeedsAttention(o,now)).length);
async function render(){
 const warnings=[];
 const app=createSSRApp({components:Object.fromEntries(['MasalMark','Users','ChevronLeft','Layers'].map(name=>[name,{template:'<i/>'}])),setup:()=>({mobileMenu:false,routeHref:p=>p,visibleSections:[{id:'orders',name:'الطلبات والمبيعات',icon:'i'}],managerSections:[],sliderSections:[],section:section.value,go:()=>{},attentionCount:count.value,number:String}),template:sidebar});
 app.config.warnHandler=warning=>warnings.push(warning);
 const html=await renderToString(app);assert.deepEqual(warnings,[]);return html;
}
assert.match(await render(),/طلبات تحتاج متابعة: 2/);
section.value='orders';assert.match(await render(),/طلبات تحتاج متابعة: 2/,'Opening orders cannot acknowledge pending work');
rows[0].paymentStatus='confirmed';rows[0].fulfillmentStatus='delivered';assert.match(await render(),/طلبات تحتاج متابعة: 1/);
rows[1].paymentStatus='confirmed';rows[1].fulfillmentStatus='delivered';assert.ok(!(await render()).includes('admin-nav-count'),'Badge disappears only after all follow-up is resolved');
console.log('Order references, Baghdad timestamps, legacy dates and persistent follow-up badge passed.');

// Render the reference cell from both real tables; use the same attention rule as the count.
for(const tableRow of source.matchAll(/<tr v-for="o in (?:orderRows|visibleAdminOrders\.slice\(0,5\))"[\s\S]*?<\/tr>/g)){
 const row=tableRow[0].replace(/v-for="[^"]+"/,'');
 const cell=row.match(/<tr[^>]*>/)[0]+row.match(/<td><span v-if="needsAttention\(o\)"[\s\S]*?<\/td>/)[0]+'</tr>';
 async function renderRow(o){return renderToString(createSSRApp({setup:()=>({o,selectedOrders:[],needsAttention:value=>orderNeedsAttention(value,now),orderRef:shortOrderReference,orderCreatedLabel}),template:'<table><tbody>'+cell+'</tbody></table>'}));}
 const active=await renderRow({...order,paymentStatus:'pending',fulfillmentStatus:'reserved',reservationExpiresAt:now+60000});
 assert.match(active,/class="admin-order-attention"/);assert.match(active,/يحتاج متابعة/);
 for(const complete of [{paymentStatus:'confirmed',fulfillmentStatus:'delivered'},{paymentStatus:'pending',fulfillmentStatus:'expired'},{paymentStatus:'pending',fulfillmentStatus:'cancelled'}]){
  const html=await renderRow({...order,...complete});assert.ok(!html.includes('admin-order-attention'),'Handled orders have no follow-up highlight');
 }
}
assert.equal([...source.matchAll(/<tr v-for="o in (?:orderRows|visibleAdminOrders\.slice\(0,5\))"/g)].length,2);
console.log('Both dashboard order tables highlight exactly the orders counted for follow-up.');

const frozen={name:'Original sale',value:5000,unit:'IQD',saleSnapshot:{version:1,name:'Original sale',unit:'IQD',denominationValue:5000,denominationName:'Original category',total:'5250.001',translations:{en:{name:'Frozen English',denominationName:'Frozen category'}}}};
assert.equal(presentOrder(frozen,'en').name,'Frozen English');
assert.equal(orderDenomination(presentOrder(frozen,'en')),'Frozen category');
assert.equal(orderDenomination({...frozen,saleSnapshot:undefined}),'5000');
assert.equal(presentOrder({name:'Legacy name'},'en').name,'Legacy name');
for(const value of [null,0,undefined])assert.equal(orderDenomination({digitalProvider:'topup',value}),'خدمة رقمية');
assert.equal(orderDenomination({digitalProvider:'topup',value:0,saleSnapshot:{serviceTitle:'Weekly bundle'}}),'Weekly bundle');
assert.equal(orderDenomination({value:null}),'—');
assert.equal(frozen.name,'Original sale','Locale presentation must not mutate the stored snapshot');
console.log('Frozen multilingual sales and service labels preserve historical identity.');
