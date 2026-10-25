import assert from 'node:assert/strict';
import {artisan,start,stop,base,probe,env} from './laravel-harness.mjs';
const headers={'Content-Type':'application/json','X-Fatya-Request':'1',Accept:'application/json'};
async function request(path,body,extra={},method='POST'){
  const r=await fetch(base+'/api'+path,{method,headers:{...headers,...extra},body:body===undefined?undefined:JSON.stringify(body)});
  return {status:r.status,body:await r.json(),cookie:r.headers.get('set-cookie')};
}
try{
  artisan('migrate:fresh','--force');artisan('fatya:seed');await start();
  let r=await request('/admin/login',{username:'masal',password:'test-password-123456'});
  assert.equal(r.status,200);assert.match(r.cookie,/httponly/i);assert.match(r.cookie,/samesite=strict/i);
  const cookie=r.cookie.split(';')[0];
  r=await request('/auth/guest',{}, {Origin:'https://untrusted.example'});assert.equal(r.status,403);
  const simple=await fetch(base+'/api/auth/guest',{method:'POST',headers:{'Content-Type':'application/json'},body:'{}'});assert.equal(simple.status,403);
  const malformed=await fetch(base+'/api/auth/guest',{method:'POST',headers,body:'{broken'});assert.equal(malformed.status,400);
  r=await request('/state',undefined,{},'GET');assert.equal(r.status,200);assert.ok(!('codes' in r.body));
  const s=(await request('/admin/state',undefined,{Cookie:cookie},'GET')).body;
  const updates=await Promise.all(['First edit','Second edit'].map(name=>request('/admin/settings',{revision:s.revision,data:{...s.settings,name}},{Cookie:cookie},'PUT')));
  assert.deepEqual(updates.map(r=>r.status).sort(),[200,409],'Concurrent writes cannot silently overwrite one another');
  const current=(await request('/admin/state',undefined,{Cookie:cookie},'GET')).body;
  const p=current.products[0];
  r=await request('/admin/import',{revision:current.revision,name:'security.xlsx',rows:[{product_id:p.id,denomination:p.values[0],serial:'Case-Sensitive-01',code:'PRIVATE-ENCRYPTED-CARD'}]},{Cookie:cookie});
  assert.equal(r.body.accepted,1);
  const stored=probe("echo json_encode((array) Illuminate\\Support\\Facades\\DB::table('inventory_cards')->first());");
  assert.ok(!stored.encrypted_code.includes('PRIVATE-ENCRYPTED-CARD'));
  assert.equal(stored.code_hash.length,64);
  r=await request('/admin/cards/search',{serial:'case-sensitive-01'},{Cookie:cookie});assert.equal(r.body.card,null);
  r=await request('/admin/cards/search',{serial:'Case-Sensitive-01'},{Cookie:cookie});assert.equal(r.body.card.serial,'Case-Sensitive-01');assert.ok(!JSON.stringify(r.body).includes('PRIVATE-ENCRYPTED-CARD'));
  const tokens=probe("echo json_encode(Illuminate\\Support\\Facades\\DB::table('auth_tokens')->pluck('token'));");
  assert.ok(tokens.every(t=>t.length===64&&!cookie.includes(t)),'Session secrets are hashed at rest');
  const longPassword='x'.repeat(127)+'A';
  r=await request('/auth/register',{phone:'07898765432',name:'Long Password',email:'',password:longPassword});assert.equal(r.status,200);
  r=await request('/auth/login',{phone:'07898765432',password:'x'.repeat(127)+'B'});assert.equal(r.status,401,'Passwords differing after byte 72 remain distinct');
  r=await request('/auth/login',{phone:'07898765432',password:longPassword});assert.equal(r.status,200);
  // OTP attempts are database-backed and remain exhausted after a PHP restart.
  r=await request('/auth/start',{mode:'register',phone:'07798765432',name:'OTP Test',email:''});
  const challenge=r.body;
  const wrong=challenge.previewCode==='111111'?'222222':'111111';
  for(let i=0;i<5;i++)assert.equal((await request('/auth/verify',{challengeId:challenge.challengeId,code:wrong})).status,400);
  await stop();await start();
  assert.equal((await request('/auth/verify',{challengeId:challenge.challengeId,code:challenge.previewCode})).status,400);
  // Rate limit is shared across process restarts, not a process-local map.
  for(let i=0;i<9;i++)assert.equal((await request('/admin/login',{username:'masal',password:'wrong'})).status,401);
  await stop();await start();
  assert.equal((await request('/admin/login',{username:'masal',password:'wrong'})).status,429);
  await stop();env.APP_ENV='production';env.SESSION_SECURE_COOKIE='true';env.DEV_OTP='true';await start();
  r=await request('/auth/guest',{});assert.equal(r.status,200);assert.match(r.cookie,/;\s*secure/i,'Production session cookies require HTTPS');
  r=await request('/auth/start',{mode:'register',phone:'07798765433',name:'Production OTP',email:''});assert.equal(r.status,503);assert.ok(!('previewCode' in r.body),'Production never reveals development OTP codes');
  console.log('Laravel origin protection, validation, conflict handling, encrypted inventory, hashed sessions, persistent OTP attempts and rate limits passed.');
}finally{await stop();}
