import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {createServer} from 'node:net';
import {artisan,start,stop,base,probe,env,confirmPayment} from './laravel-harness.mjs';
import {copyOrderCodes} from '../src/order-delivery.js';
let peer,peerBase;
async function startPeer(){
  const server=createServer();await new Promise(r=>server.listen(0,'127.0.0.1',r));const port=server.address().port;await new Promise(r=>server.close(r));
  peer=spawn(process.env.PHP_BIN||'php',['-S','127.0.0.1:'+port,'../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'],{cwd:new URL('../backend/public/',import.meta.url),env,stdio:'ignore'});peerBase='http://127.0.0.1:'+port;
  for(let i=0;i<100;i++){try{if((await fetch(peerBase+'/api/health')).ok)return;}catch{}await new Promise(r=>setTimeout(r,50));}throw Error('Second PHP worker did not start');
}
function client(){let cookie='';return async(path,body,method=body===undefined?'GET':'POST',status=200,target=base)=>{const r=await fetch(target+'/api'+path,{method,headers:{Cookie:cookie,Accept:'application/json',...(body===undefined?{}:{'Content-Type':'application/json','X-Fatya-Request':'1'})},body:body===undefined?undefined:JSON.stringify(body)});if(r.headers.get('set-cookie'))cookie=r.headers.get('set-cookie').split(';')[0];const data=await r.json();if(status!==null)assert.equal(r.status,status,JSON.stringify(data));return {status:r.status,data,headers:r.headers};};}
const DB='Illuminate\\Support\\Facades\\DB';
const phpValue=value=>"json_decode(base64_decode('"+Buffer.from(JSON.stringify(value)).toString('base64')+"'),true)";
const query=(table,where={})=>DB+"::table('"+table+"')->where("+phpValue(where)+")";
const count=(table,where={})=>probe('echo json_encode('+query(table,where)+'->count());');
const update=(table,where,data)=>probe(query(table,where)+'->update('+phpValue(data)+'); echo json_encode(true);');
function asynchronousConfirm(order,amount,transaction){
  return new Promise((resolve,reject)=>{
    const child=spawn(process.env.PHP_BIN||'php',['artisan','fatya:simulate-payment',order,'--amount='+amount,'--provider=Qi','--transaction='+transaction,'--event='+transaction,'--no-ansi'],{cwd:new URL('../backend/',import.meta.url),env});
    let output='',error='';child.stdout.on('data',d=>output+=d);child.stderr.on('data',d=>error+=d);child.on('exit',code=>code===0?resolve(JSON.parse(output.trim())):reject(Error(output+error)));child.on('error',reject);
  });
}
try{
  artisan('migrate:fresh','--force');artisan('fatya:seed');await start();await startPeer();
  const admin=client(),alice=client(),bob=client(),anon=client();
  await admin('/admin/login',{username:'masal',password:'test-password-123456'});
  const a=(await alice('/auth/guest',{})).data;await bob('/auth/guest',{});
  let state=(await admin('/admin/state')).data;const p=state.products[0];let sequence=0;
  const importCards=async(number,denom=0)=>{state=(await admin('/admin/state')).data;const rows=Array.from({length:number},()=>{const i=++sequence;return {product_id:p.id,denomination:p.values[denom],code:'FULFILLMENT-SECRET-'+i,serial:'00000-fulfillment-'+i};});const r=await admin('/admin/import',{revision:state.revision,name:'fulfillment.xlsx',rows});assert.equal(r.data.accepted,number);return rows;};
  const request=(reference,quantity=1,denom=0,payment='Qi')=>({productId:p.id,denom,quantity,payment,reference,paid:true,paymentStatus:'confirmed',price:1});
  const stock=async()=>((await admin('/admin/state')).data.products.find(x=>x.id===p.id)).stock[0];
  await importCards(1);
  await alice('/orders',request('partial',2),'POST',409);assert.equal(await stock(),1);assert.equal(count('reservation_items'),0);
  const race=await Promise.all([alice('/orders',request('race-a'),'POST',null,base),bob('/orders',request('race-b'),'POST',null,peerBase)]);
  assert.deepEqual(race.map(r=>r.status).sort(),[201,409],'Two PHP workers cannot reserve the same last card');
  const owner=race[0].status===201?alice:bob,other=owner===alice?bob:alice,order=race.find(r=>r.status===201).data;
  assert.equal(order.paymentStatus,'pending');assert.equal(order.codesAvailable,false);assert.equal(await stock(),0);
  await owner('/orders',request(owner===alice?'race-a':'race-b'),'POST',200);assert.equal(await stock(),0);
  await owner('/orders',{...request(owner===alice?'race-a':'race-b'),quantity:2},'POST',409);
  await owner('/orders/'+order.id+'/codes',undefined,'GET',409);await other('/orders/'+order.id+'/codes',undefined,'GET',404);await anon('/orders/'+order.id+'/codes',undefined,'GET',401);await admin('/orders/'+order.id+'/codes',undefined,'GET',401);
  state=(await admin('/admin/state')).data;await admin('/admin/orders',{revision:state.revision,data:{id:order.id,status:'مكتمل',paid:true}},'PUT',409);
  await owner('/payments/confirm',{orderId:order.id,paid:true},'POST',404);await admin('/admin/payments/confirm',{orderId:order.id},'POST',404);
  let writes=0;await assert.rejects(copyOrderCodes(order,async path=>(await owner(path,undefined,'GET',409)).data,{writeText:async()=>writes++}));assert.equal(writes,0);
  assert.equal(confirmPayment(order.id,'1','wrong-amount').reason,'amount_mismatch');
  assert.equal(confirmPayment(order.id,String(order.price),'wrong-currency','Qi','USD').reason,'currency_mismatch');
  assert.equal(confirmPayment(order.id,String(order.price),'wrong-provider','ZainCash').reason,'provider_mismatch');
  await owner('/orders/'+order.id+'/codes',undefined,'GET',409);
  const confirmations=await Promise.all([asynchronousConfirm(order.id,String(order.price),'paid-once'),asynchronousConfirm(order.id,String(order.price),'paid-once')]);
  assert.equal(confirmations.filter(r=>r.duplicate).length,1);assert.ok(confirmations.every(r=>r.outcome==='delivered'));
  const delivered=(await owner('/orders/'+order.id+'/codes')).data;assert.equal(delivered.codes.length,1);assert.equal(delivered.codes[0].code,'FULFILLMENT-SECRET-1');
  assert.match((await owner('/orders/'+order.id+'/codes')).headers.get('cache-control'),/no-store/);assert.equal(count('delivered_cards'),1);
  assert.equal(confirmPayment(order.id,String(order.price),'paid-once','Qi','IQD','new-retry-event').duplicate,true);
  assert.equal(confirmPayment(order.id,String(order.price),'extra-payment').reason,'additional_payment');assert.equal(count('delivered_cards'),1);
  assert.throws(()=>confirmPayment(order.id,'1','paid-once'),/مرجع الدفع/,'Changed amount on the same transaction is rejected');
  state=(await admin('/admin/state')).data;const reimport=await admin('/admin/import',{revision:state.revision,name:'sold-duplicate.xlsx',rows:[{product_id:p.id,denomination:p.values[0],code:'FULFILLMENT-SECRET-1',serial:'another-serial'}]});assert.equal(reimport.data.accepted,0,'A sold code cannot be imported again');assert.equal(await stock(),0);
  let clipboard='';await copyOrderCodes(order,async path=>(await owner(path)).data,{writeText:async text=>clipboard=text});assert.equal(clipboard,'FULFILLMENT-SECRET-1');
  assert.ok(!JSON.stringify((await owner('/state')).data).includes('FULFILLMENT-SECRET'));assert.ok(!JSON.stringify((await admin('/admin/state')).data).includes('FULFILLMENT-SECRET'));
  await stop();await start();assert.deepEqual((await owner('/orders/'+order.id+'/codes')).data,delivered);
  await importCards(1);const cancelled=(await alice('/orders',request('cancelled'),'POST',201)).data;
  state=(await admin('/admin/state')).data;await admin('/admin/orders',{revision:state.revision,data:{id:cancelled.id,status:'ملغي'}},'PUT');assert.equal(await stock(),1);
  const expired=(await alice('/orders',request('expired'),'POST',201)).data;update('order_fulfillments',{order_id:expired.id},{expires:1});artisan('fatya:expire-reservations');assert.equal(await stock(),1);
  const winner=(await bob('/orders',request('reused-after-expiry'),'POST',201)).data;
  assert.equal(confirmPayment(expired.id,String(expired.price),'late-expired').reason,'reservation_expired');
  assert.equal(confirmPayment(cancelled.id,String(cancelled.price),'late-cancelled').reason,'reservation_cancelled');
  await alice('/orders/'+expired.id+'/codes',undefined,'GET',409);assert.equal(await stock(),0);
  assert.equal(confirmPayment(winner.id,String(winner.price),'winner-payment').outcome,'delivered');
  assert.notEqual((await bob('/orders/'+winner.id+'/codes')).data.codes[0].code,delivered.codes[0].code);
  assert.throws(()=>confirmPayment(winner.id,String(winner.price),'paid-once'),/مرجع الدفع/);
  state=(await admin('/admin/state')).data;await admin('/admin/orders',{revision:state.revision,data:{id:winner.id,status:'ملغي'}},'PUT',409);
  await importCards(1);const suspended=(await alice('/orders',request('suspended'),'POST',201)).data;
  state=(await admin('/admin/state')).data;await admin('/admin/customers',{revision:state.revision,data:{...state.customers.find(c=>c.id===a.id),active:false}},'PUT');
  assert.equal(confirmPayment(suspended.id,String(suspended.price),'suspended-payment').reason,'customer_inactive');await alice('/orders/'+suspended.id+'/codes',undefined,'GET',403);assert.equal(await stock(),1);
  await importCards(1);const damaged=(await bob('/orders',request('damaged',2),'POST',201)).data;
  const original=probe('echo json_encode((array) '+query('inventory_cards',{reserved_order_id:damaged.id})+"->orderByDesc('id')->first());");
  update('inventory_cards',{id:original.id},{encrypted_code:'broken'});assert.throws(()=>confirmPayment(damaged.id,String(damaged.price),'damaged-payment'));
  assert.equal(count('payment_receipts',{transaction_id:'damaged-payment'}),0,'Decryption error rolls back all acceptance/delivery writes');
  assert.equal(count('delivered_cards',{order_id:damaged.id}),0,'A valid first card cannot be delivered when the second card fails');
  assert.equal(count('inventory_cards',{reserved_order_id:damaged.id}),2);
  update('inventory_cards',{id:original.id},{encrypted_code:original.encrypted_code});assert.equal(confirmPayment(damaged.id,String(damaged.price),'damaged-payment').outcome,'delivered');
  await importCards(2);const multiple=(await bob('/orders',request('multiple',2),'POST',201)).data;assert.equal(confirmPayment(multiple.id,String(multiple.price),'multiple-payment').outcome,'delivered');
  assert.equal((await bob('/orders/'+multiple.id+'/codes')).data.codes.length,2);assert.equal(count('delivered_cards'),6);
  // The boundary is exclusive: a receipt at/after expiry is held for review.
  await importCards(1,1);const boundary=(await bob('/orders',request('boundary',1,1),'POST',201)).data;
  update('order_fulfillments',{order_id:boundary.id},{expires:Math.floor(Date.now()/1000)});
  assert.equal(confirmPayment(boundary.id,String(boundary.price),'boundary-payment').reason,'reservation_expired');await bob('/orders/'+boundary.id+'/codes',undefined,'GET',409);
  // Race cancellation against a trusted receipt on another process.
  const contested=(await bob('/orders',request('cancel-payment-race',1,1),'POST',201)).data;state=(await admin('/admin/state')).data;
  const [cancel,paid]=await Promise.all([admin('/admin/orders',{revision:state.revision,data:{id:contested.id,status:'ملغي'}},'PUT',null),asynchronousConfirm(contested.id,String(contested.price),'cancel-race-payment')]);
  if(paid.outcome==='delivered'){assert.equal(cancel.status,409);assert.equal((await bob('/orders/'+contested.id+'/codes')).data.codes.length,1);}else{assert.equal(cancel.status,200);assert.equal(paid.reason,'reservation_cancelled');await bob('/orders/'+contested.id+'/codes',undefined,'GET',409);}
  // Pending-order cap does not consume a sixth card; expiry is lazy as well as scheduled.
  await importCards(6,2);const holds=[];for(let i=0;i<5;i++)holds.push((await bob('/orders',request('hold-'+i,1,2),'POST',201)).data);
  await bob('/orders',request('sixth-hold',1,2),'POST',429);
  for(const hold of holds)update('order_fulfillments',{order_id:hold.id},{expires:1});
  const renewed=(await bob('/orders',request('after-lazy-expiry',1,2),'POST',201)).data;assert.equal(renewed.fulfillmentStatus,'reserved');
  const oldRetry=(await bob('/orders',request('hold-0',1,2),'POST',200)).data;assert.equal(oldRetry.fulfillmentStatus,'expired');
  // Decimal arithmetic is exact, and the frozen order price survives a catalog edit.
  await importCards(3,3);state=(await admin('/admin/state')).data;const priced=state.products.find(x=>x.id===p.id);priced.prices[3]=1234.125;
  await admin('/admin/products',{revision:state.revision,data:priced},'PUT');
  const decimal=(await bob('/orders',request('decimal-money',3,3),'POST',201)).data;assert.equal(decimal.price,3702.375);
  state=(await admin('/admin/state')).data;const repriced=state.products.find(x=>x.id===p.id);repriced.prices[3]=9999;
  await admin('/admin/products',{revision:state.revision,data:repriced},'PUT');
  assert.equal(confirmPayment(decimal.id,'3702.375','decimal-payment').outcome,'delivered');assert.equal((await bob('/orders/'+decimal.id+'/codes')).data.codes.length,3);
  await importCards(1,1);const incomplete=(await bob('/orders',request('incomplete-allocation',1,1),'POST',201)).data;
  update('inventory_cards',{reserved_order_id:incomplete.id},{reserved_order_id:null});assert.equal(confirmPayment(incomplete.id,String(incomplete.price),'incomplete-payment').reason,'allocation_incomplete');await bob('/orders/'+incomplete.id+'/codes',undefined,'GET',409);
  env.ALLOW_PAYMENT_SIMULATION='false';assert.throws(()=>confirmPayment(multiple.id,String(multiple.price),'disabled'),/disabled/);env.ALLOW_PAYMENT_SIMULATION='true';env.APP_ENV='production';assert.throws(()=>confirmPayment(multiple.id,String(multiple.price),'production'),/disabled/);env.APP_ENV='testing';
  console.log('Reservation/delivery passed: two PHP workers, exact stock, payment trust boundary, amount/currency/provider checks, replay safety, owner-only codes, expiry/cancel/reuse, suspension, atomic rollback, multi-card delivery, restart and production simulator denial.');
}finally{if(peer&&peer.exitCode===null){peer.kill();await new Promise(r=>peer.once('exit',r));}await stop();}
