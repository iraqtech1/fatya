import assert from 'node:assert/strict';
import {registerHooks} from 'node:module';
import {artisan,start,stop,base,probe} from './laravel-harness.mjs';
import * as Vue from 'vue/dist/vue.esm-bundler.js';

// Exercise the real component setup and store against Laravel, without a DOM
// layout dependency. Browser testing separately verifies the existing templates.
registerHooks({load(url,context,next){if(url.endsWith('.css'))return {format:'module',source:'export default {};',shortCircuit:true};return next(url,context);}});
globalThis.window={addEventListener(){},removeEventListener(){},scrollTo(){}};
globalThis.location={hash:'#/admin'};
globalThis.document={documentElement:{lang:'ar',dir:'rtl'},title:'فاتيا'};
const originalFetch=globalThis.fetch,cookies=new Map();let failSnapshot=false;
globalThis.fetch=async(input,options={})=>{
  const url=new URL(input,base);
  if(failSnapshot&&url.pathname==='/api/state'){failSnapshot=false;throw Error('Snapshot temporarily unavailable');}
  const response=await originalFetch(url,{...options,headers:{...options.headers,Cookie:[...cookies].map(([k,v])=>k+'='+v).join('; ')}});
  for(const entry of response.headers.getSetCookie()){const [pair]=entry.split(';');const index=pair.indexOf('=');cookies.set(pair.slice(0,index),pair.slice(index+1));}
  return response;
};
const renderer=Vue.createRenderer({insert(){},remove(){},createElement:()=>({}),createText:()=>({}),createComment:()=>({}),setText(){},setElementText(){},parentNode:()=>null,nextSibling:()=>null,patchProp(){}});
const waitFor=async predicate=>{for(let i=0;i<100;i++){if(predicate())return;await new Promise(r=>setTimeout(r,25));}assert.fail('Component did not settle');};
let app;
try{
  artisan('migrate:fresh','--force');artisan('fatya:seed');await start();
  const {api}=await import('../src/api.js');
  const {store,connection,setStoreRole,refreshStore,saveAdmin,createOrder,createTicket,applyState}=await import('../src/store.js');
  const {default:Dashboard}=await import('../src/Dashboard.js');
  await api('/admin/login',{username:'masal',password:'test-password-123456'});
  app=renderer.createApp({...Dashboard,render:()=>null});const dashboard=app.mount({});
  await waitFor(()=>dashboard.authenticated);
  await saveAdmin('categories',{name:'Integration category',en:'Integration category',icon:'Layers',active:true});await Vue.nextTick();
  dashboard.settings.name='Connected settings';await dashboard.saveSettings();
  assert.equal(dashboard.notice,'تم حفظ الإعدادات','Settings save on first attempt after editing another collection');
  assert.equal((await api('/state')).settings.name,'Connected settings');
  const stale=await api('/admin/state');await saveAdmin('categories',{name:'Second category',en:'Second category',icon:'Layers',active:true});applyState(stale);
  assert.ok(store.categories.some(c=>c.name==='Second category'),'An older polling response cannot overwrite a successful administrator save');
  let s=await api('/admin/state');
  await api('/admin/settings',{revision:s.revision,data:{...s.settings,supportPhone:'07712345678'}},'PUT');await refreshStore();await Vue.nextTick();
  assert.equal(dashboard.settings.supportPhone,'07712345678','Untouched settings follow server changes');
  dashboard.settings.name='Unsaved draft';s=await api('/admin/state');
  await api('/admin/settings',{revision:s.revision,data:{...s.settings,name:'Another administrator'}},'PUT');await refreshStore();await Vue.nextTick();
  assert.equal(dashboard.settings.name,'Unsaved draft','Polling does not erase an unsaved settings draft');
  await dashboard.saveSettings();assert.match(dashboard.notice,/البيانات تغيرت/);assert.equal((await api('/state')).settings.name,'Another administrator');
  app.unmount();app=null;assert.equal(connection.admin,false,'Leaving dashboard resets the active API role');assert.equal(store.customers.length,0);await refreshStore();
  const user=await api('/auth/register',{name:'Connected Customer',phone:'07812345678',email:'',password:'connected-password'});await refreshStore();
  assert.equal(connection.customer.id,user.id);
  const p=store.products[0];failSnapshot=true;
  const order=await createOrder(p,0,1,user,'Qi','connected-order');
  assert.ok(store.orders.some(o=>o.id===order.id),'Confirmed order remains visible if the subsequent snapshot fails');
  assert.equal(probe("echo json_encode(Illuminate\\Support\\Facades\\DB::table('orders')->count());"),1);
  failSnapshot=true;const ticket=await createTicket('Connected support','A test message',user);
  assert.ok(store.tickets.some(t=>t.id===ticket.id),'A committed ticket is not reported as failed because polling is offline');
  await refreshStore();assert.equal(store.tickets.length,1);assert.equal(store.orders.length,1);
  s=await api('/admin/state');const c=s.customers.find(c=>c.id===user.id);
  await api('/admin/customers',{revision:s.revision,data:{...c,name:'Changed by admin'}},'PUT');await refreshStore();assert.equal(connection.customer.name,'Changed by admin');
  s=await api('/admin/state');await api('/admin/customers',{revision:s.revision,data:{...s.customers.find(c=>c.id===user.id),active:false}},'PUT');await refreshStore();
  assert.equal(connection.customer,null,'Suspension is visible to an already-open application');assert.equal(store.orders.length,0);assert.equal(store.tickets.length,0);
  setStoreRole(true);await refreshStore();assert.ok(store.customers.length);
  probe("Illuminate\\Support\\Facades\\DB::table('auth_tokens')->where('role','admin')->delete(); echo json_encode(true);");
  await assert.rejects(refreshStore(),e=>e.status===401);assert.equal(connection.admin,false);assert.equal(store.customers.length,0);
  await stop();await start();
  assert.equal((await api('/state')).settings.name,'Another administrator');
  assert.equal(probe("echo json_encode([Illuminate\\Support\\Facades\\DB::table('orders')->count(),Illuminate\\Support\\Facades\\DB::table('tickets')->count()]);").join(','),'1,1');
  console.log('Real Vue dashboard/store integration passed: settings revisions and drafts, role cleanup, customer updates/suspension, committed writes with failed snapshots, MariaDB persistence and expired administrator sessions.');
}finally{app?.unmount();globalThis.fetch=originalFetch;await stop();}
