import assert from 'node:assert/strict';
import {artisan,start,stop,base,probe} from './laravel-harness.mjs';
function client(){let cookie='';return async(path,body,method=body===undefined?'GET':'POST',status=200)=>{const r=await fetch(base+'/api'+path,{method,headers:{Cookie:cookie,Accept:'application/json',...(body===undefined?{}:{'Content-Type':'application/json','X-Fatya-Request':'1'})},body:body===undefined?undefined:JSON.stringify(body)});if(r.headers.get('set-cookie'))cookie=r.headers.get('set-cookie').split(';')[0];const data=await r.json();assert.equal(r.status,status,JSON.stringify(data));return data;};}
try{
  artisan('migrate:fresh','--force');artisan('fatya:seed');await start();
  const a=client(),b=client(),admin=client(),anonymous=client();
  await a('/auth/guest',{});await b('/auth/guest',{});await admin('/admin/login',{username:'masal',password:'test-password-123456'});
  assert.equal((await a('/state')).notifications.length,3);assert.equal((await b('/state')).notifications.length,3);
  let state=await admin('/admin/state');const p=state.products[0];
  const request={productId:p.id,denom:0,quantity:1,payment:'Qi',reference:'notification-order'};
  const order=await a('/orders',request,'POST',201);await a('/orders',request);assert.equal((await a('/state')).notifications.length,4,'Retrying an order does not duplicate its notification');
  const ticket=await a('/tickets',{subject:'Notification test',message:'A support request'},'POST',201);
  state=await admin('/admin/state');state=await admin('/admin/orders',{revision:state.revision,data:{id:order.id,status:'مكتمل'}},'PUT');
  await admin('/admin/tickets',{revision:state.revision,data:{id:ticket.id,status:'قيد المعالجة',reply:'A saved reply'}},'PUT');
  const notes=(await a('/state')).notifications;assert.equal(notes.length,7);assert.ok(notes.some(n=>n.title==='تحديث حالة الطلب'));assert.ok(notes.some(n=>n.title==='تحديث تذكرة الدعم'));
  assert.equal((await b('/state')).notifications.length,3,'Other accounts do not receive private order or support notifications');
  const privateNote=notes.find(n=>n.title==='تحديث تذكرة الدعم');
  await b('/notifications/read',{ids:[privateNote.id]},'POST',404);await anonymous('/notifications/read',{ids:[privateNote.id]},'POST',401);
  const marked=await a('/notifications/read',{ids:[privateNote.id]});assert.equal(marked.notifications.find(n=>n.id===privateNote.id).unread,false);
  const rest=await a('/notifications/read',{ids:notes.map(n=>n.id)});assert.ok(rest.notifications.every(n=>!n.unread));
  await stop();await start();assert.ok((await a('/state')).notifications.every(n=>!n.unread),'Read status survives process restart');assert.ok((await b('/state')).notifications.every(n=>n.unread));
  assert.equal((await anonymous('/state')).notifications.length,0);
  assert.equal(probe("echo json_encode(Illuminate\\Support\\Facades\\DB::table('customer_notifications')->count());"),10);
  console.log('MariaDB notifications passed: welcome/order/support events, status/reply updates, read-all persistence, retry deduplication and customer isolation.');
}finally{await stop();}
