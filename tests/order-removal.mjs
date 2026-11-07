import assert from 'node:assert/strict';
import {registerHooks} from 'node:module';
import * as Vue from 'vue/dist/vue.esm-bundler.js';
import {readPreviewOrders} from '../src/preview-orders.js';

registerHooks({load(url,context,next){if(url.endsWith('.css'))return {format:'module',source:'export default {};',shortCircuit:true};return next(url,context);}});
const saved=new Map();let failStorage=false;
globalThis.localStorage={getItem:key=>saved.get(key)??null,setItem(key,value){if(failStorage)throw Error('storage full');saved.set(key,value);}};
globalThis.document={documentElement:{lang:'ar',dir:'rtl'},title:'فاتيا'};
// Resolve the preview transport before installing the minimal UI surface.
await import('../src/api.js');
globalThis.window={addEventListener(){},removeEventListener(){},scrollTo(){}};
const {default:Dashboard}=await import('../src/Dashboard.js');
const {store}=await import('../src/store.js');
const base={name:'Selection fixture',customer:'Fixture',date:'2026-11-08',price:5250,value:5000,quantity:1,status:'مكتمل',phone:'07712345678'};
store.orders.splice(0,store.orders.length,...Array.from({length:12},(_,i)=>({...base,id:'order-'+i})),{...base,id:'hidden',adminHiddenAt:'2026-11-08T00:00:00Z'});
globalThis.window={addEventListener(){},removeEventListener(){},scrollTo(){}};
globalThis.location={pathname:'/admin/orders'};
const renderer=Vue.createRenderer({insert(){},remove(){},createElement:()=>({}),createText:()=>({}),createComment:()=>({}),setText(){},setElementText(){},parentNode:()=>null,nextSibling:()=>null,patchProp(){}});
const app=renderer.createApp({...Dashboard,render:()=>null});
const ui=app.mount({});ui.dialog={showModal(){},close(){}};
try{
 assert.equal(ui.orders.length,12);const gross=ui.gross;
 const page=ui.orders.slice(0,10);ui.toggleOrderPage(page,true);
 assert.equal(ui.selectedOrders.length,10);assert.equal(ui.pageSelected(page),true);
 ui.toggleOrder('order-10',true);assert.equal(ui.selectedOrders.length,11,'Selections persist across pages');
 ui.toggleOrderPage(page,false);assert.deepEqual([...ui.selectedOrders],['order-10']);
 ui.query='not found';await Vue.nextTick();assert.equal(ui.selectedOrders.length,0,'Filtering cannot silently delete previously selected invisible orders');
 ui.query='';await Vue.nextTick();
 ui.toggleOrder('order-0',true);ui.toggleOrder('order-1',true);
 ui.askDeleteOrders(ui.selectedOrders);await Vue.nextTick();assert.equal(ui.modal,'deleteOrders');assert.deepEqual([...ui.draft.ids],['order-0','order-1']);
 ui.close();assert.equal(ui.orders.length,12,'Cancelling does not hide anything');
 ui.askDeleteOrders(ui.selectedOrders);await Vue.nextTick();
 failStorage=true;await ui.deleteOrders();assert.equal(ui.formError,'storage full');assert.equal(ui.modal,'deleteOrders');assert.equal(ui.orders.length,12,'Failed persistence cannot hide an order locally');
 failStorage=false;await ui.deleteOrders();await Vue.nextTick();
 assert.equal(ui.modal,null);assert.equal(ui.selectedOrders.length,0);assert.equal(ui.orders.length,10);assert.equal(store.orders.length,13);
 assert.equal(ui.gross,gross,'Administrative deletion preserves sales totals');
 assert.equal(readPreviewOrders(globalThis.localStorage).filter(o=>o.adminHiddenAt).length,3,'Hidden state survives reload');
 ui.askDeleteOrders(['order-2']);await Vue.nextTick();assert.deepEqual([...ui.draft.ids],['order-2']);await ui.deleteOrders();
 assert.equal(ui.orders.length,9,'Single-order deletion shares the bulk flow');
 assert.equal(store.orders.find(o=>o.id==='order-2').status,'مكتمل');
 console.log('Real dashboard selection, page selection, filtering, cancellation, single/bulk removal, failed storage and preserved totals passed.');
}finally{app.unmount();delete globalThis.window;delete globalThis.location;delete globalThis.localStorage;}
