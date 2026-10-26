import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import * as Vue from 'vue/dist/vue.esm-bundler.js';
import {isWebStore} from '../src/store-surface.js';
import {initialProducts} from '../src/catalog.js';

assert.equal(isWebStore({path:'/',standalone:false}),true);
assert.equal(isWebStore({path:'/',standalone:true}),false);
for(const path of ['/app','/admin/orders','/ar/about'])assert.equal(isWebStore({path,standalone:false}),false);

// Exercise the real root setup and handlers with a Vue renderer. Orders are
// isolated fixtures; this test never connects to the production API/database.
const source=readFileSync('src/main.js','utf8').replaceAll('\r\n','\n');
const setup=source.slice(source.indexOf('setup(){'),source.indexOf('\n},\ntemplate:')+2);
assert.ok(setup.endsWith('\n}'));
let created=0;
const store=Vue.reactive({products:initialProducts.map(p=>({...p,active:true})),categories:[...new Set(initialProducts.map(p=>p.category))].map((name,id)=>({id,name,en:name,active:true})),settings:{supportPhone:''},orders:[],tickets:[]});
const context={...Vue,console,WebStore:{},StoreLayout:{},isWebStore:()=>true,accountPages:new Set(['account','orders','payment']),inRoute:(p,prefix)=>p===prefix||p.startsWith(prefix+'/'),routePath:()=> '/',window:{addEventListener(){},removeEventListener(){},scrollTo(){}},document:{querySelector:()=>null},innerWidth:1200,connected:false,connection:Vue.reactive({admin:false,ready:false,customer:null}),store,restoreStoreSession:()=>null,saveStoreSession(){},upsertCustomer(){},refreshStore:async()=>{},matchesCustomer:(o,u)=>!!u&&o.customerId===u.id,createOrder:async()=>{created++;return {id:'FIXTURE-'+created};},createTicket:async()=>{},isAsiacell:p=>p.en==='asiacell',auth:{},t:s=>s,language:Vue.ref('ar'),cardCount:n=>String(n),crypto:globalThis.crypto,setTimeout,clearTimeout,setInterval,clearInterval};
for(const name of source.match(/import \{([^}]+)\} from 'lucide-vue-next'/)[1].split(','))context[name.trim()]={};
const component=vm.runInNewContext('({'+setup+'})',context);
const renderer=Vue.createRenderer({insert(){},remove(){},createElement:()=>({}),createText:()=>({}),createComment:()=>({}),setText(){},setElementText(){},parentNode:()=>null,nextSibling:()=>null,patchProp(){}});
const app=renderer.createApp({...component,render:()=>null});
const ui=app.mount({});
ui.dialog={showModal(){},close(){},scrollTop:0};
assert.equal(ui.webMode,true);assert.equal(ui.authVisible,false);assert.equal(ui.session,null);
await ui.open(ui.products[0]);ui.denom=1;ui.quantity=2;
ui.startCheckout();
assert.equal(ui.authVisible,true);assert.equal(ui.page,'home');assert.equal(created,0,'Guest purchase requests authentication without creating an order');
await ui.cancelAuth();assert.equal(ui.authVisible,false);assert.equal(ui.denom,1);assert.equal(ui.quantity,2);assert.equal(ui.price,12600,'Cancelling authentication restores the selected price, denomination and quantity');
ui.startCheckout();ui.requestAuth('register','payment');assert.equal(ui.authView,'register');
await ui.enterStore({id:'fixture-customer',name:'Fixture'});
assert.equal(ui.authVisible,false);assert.equal(ui.page,'payment');assert.equal(ui.paymentDraft.denom,1);assert.equal(ui.paymentDraft.quantity,2);assert.equal(created,0,'Successful authentication resumes checkout without submitting payment');
await ui.confirmPayment();assert.equal(created,1);
await ui.accountAccess();assert.equal(ui.session,null);assert.equal(ui.paymentDraft,null);assert.equal(ui.page,'home');
ui.go('orders');assert.equal(ui.authVisible,true);assert.equal(ui.page,'home');await ui.cancelAuth();
await ui.open(ui.products.find(p=>p.en==='asiacell'));
ui.checkoutAsiacell({product:ui.asiacellProduct,denom:0,quantity:1,amount:5250,method:'Qi',telecom:{recipient:'07700000000'},asiacellDraft:{service:'card'}});
assert.equal(ui.authVisible,true);await ui.enterStore({id:'fixture-customer',name:'Fixture'});assert.equal(ui.page,'payment');assert.equal(ui.paymentDraft.telecom.recipient,'07700000000');
app.unmount();
console.log('Web guest browsing, protected pages, login/register return, cancelled authentication, denomination/quantity retention, telecom checkout and logout passed.');
