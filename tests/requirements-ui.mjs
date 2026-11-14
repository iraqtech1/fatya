import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createRequire} from 'node:module';
import * as Vue from 'vue/dist/vue.esm-bundler.js';
import * as presentation from '../src/report-presentation.js';
import * as layout from '../src/catalog-display.js';
const require=createRequire(import.meta.url),{renderToString}=require('@vue/server-renderer');
const PagedList={props:['items'],template:'<slot :items="items"/>'},stub={template:'<div/>'};
const store=Vue.reactive({products:[{id:1,name:'Fixture',active:true,category:'Cards',values:[5000],prices:['5250.001'],stock:[1]}],categories:[{id:'cards',name:'Cards',active:true}],companies:[],customers:[],settings:{}}),connection={revision:5};
const requests=[];
const fixtures={
 '/admin/procurement/prices':{revision:5,rows:[{key:'product:1:0',productId:'1',name:'<script>fixture</script>',companyId:'',companyPath:[],source:'stock',importCode:'E5K',retail:'5250.001',cost:null,margin:null,unknownCostCount:1,active:true}],history:[]},
 '/admin/procurement/notifications':{campaigns:[],rules:{version:0,expiry_enabled:false,expiry_days:7,repeat_days:null,notify_customer:false,push:false},expiryAlerts:[]}
};
function component(name){const source=readFileSync('src/'+name+'.js','utf8').replace(/^import .*;\r?\n/gm,'').replace('export default','return'),dependencies={...Vue,...presentation,...layout,onMounted:()=>{},onBeforeUnmount:()=>{},api:async(path,body,method)=>{requests.push({path,body,method});if(fixtures[path])return fixtures[path];throw Error('Unexpected fixture request');},connected:true,can:()=>true,store,connection,refreshStore:async()=>{},exportWorkbook:async()=>{},readPrices:async()=>{},CompanyCatalog:stub,PagedList};const entries=Object.entries(dependencies).filter(([key])=>/^[A-Za-z_$][A-Za-z0-9_$]*$/.test(key));return Function(...entries.map(([key])=>key),source)(...entries.map(([,value])=>value));}
async function render(component,ui,props={}){const warnings=[],app=Vue.createSSRApp({...component,setup:()=>ui},props);app.directive('permit',{getSSRProps:()=>({})});app.config.warnHandler=warning=>warnings.push(warning);const html=await renderToString(app);assert.deepEqual(warnings,[],'Every visible binding must exist');return html;}
const prices=component('PriceCenter'),p=prices.setup();await p.load();const priceHtml=await render(prices,p);assert.ok(priceHtml.includes('&lt;script&gt;fixture&lt;/script&gt;'));assert.ok(priceHtml.includes('5,250.001'));assert.ok(priceHtml.includes('غير معروف'));assert.ok(!priceHtml.includes('<script>fixture</script>'));
const appDisplay=component('ApplicationManager'),a=appDisplay.setup();a.reset();a.scope.value='cards';a.setLayout('mode','row');assert.equal(a.draft.value.categories.cards.mode,'row');assert.equal(a.draft.value.default.mode,'grid');assert.equal(store.settings.catalogDisplay,undefined,'Unsaved display edits must not change shared settings');await render(appDisplay,a);
const notifications=component('NotificationManager'),n=notifications.setup();await n.load();await render(notifications,n);assert.equal(n.rules.value.expiry_enabled,false);assert.equal(requests.filter(request=>request.path.endsWith('/send')).length,0,'Viewing notification management never schedules a campaign');
const refund=component('RefundSettlement'),f=refund.setup(Vue.reactive({order:{id:'ORDER-fixture',paymentStatus:'confirmed',price:'5250.001'}}),{emit:()=>{}});f.opened.value=true;await render(refund,f,{order:{id:'ORDER-fixture',paymentStatus:'confirmed',price:'5250.001'}});assert.equal(requests.filter(request=>request.path.includes('/settle')).length,0,'Opening the refund form cannot record a repayment');
console.log('Price, display, notification and refund screens render without missing bindings; drafts remain private, text is escaped and viewing sends no campaigns or refunds.');
