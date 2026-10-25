import assert from 'node:assert/strict';
import {DatabaseSync} from 'node:sqlite';
import {mkdtempSync,readFileSync,rmSync} from 'node:fs';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';
import {scryptSync,createHash} from 'node:crypto';
import {artisan,start,stop,base,probe} from './laravel-harness.mjs';
const temp=mkdtempSync(fileURLToPath(new URL('../../fatya-import-test-',import.meta.url)));
const file=join(temp,'legacy.sqlite');
const seed=JSON.parse(readFileSync(new URL('../backend/database/seeders/catalog.json',import.meta.url),'utf8'));
const salt='0123456789abcdef0123456789abcdef',password='legacy-password-123';
const customer={id:'old-customer',name:'Existing Customer',phone:'07712345678',email:'',active:true,kind:'فرد',date:'2026-10-25'};
const state={revision:12,...seed,settings:{...seed.settings,name:'ماسال'},customers:[customer],orders:[],tickets:[],suppliers:[],imports:[],events:[],slides:[],codes:[{productId:seed.products[0].id,value:seed.products[0].values[0],code:'LEGACY-PRIVATE-CODE',serial:'00000999'}]};
delete state.categories;delete state.companies;
state.products[0].stock[0]=1;
const db=new DatabaseSync(file);
db.exec('CREATE TABLE state (id INTEGER PRIMARY KEY,value TEXT); CREATE TABLE credentials (customer TEXT PRIMARY KEY,salt TEXT,digest TEXT);');
db.prepare('INSERT INTO state VALUES (1,?)').run(JSON.stringify(state));
db.prepare('INSERT INTO credentials VALUES (?,?,?)').run(customer.id,salt,scryptSync(password,salt,64).toString('hex'));db.close();
const fingerprint=()=>createHash('sha256').update(readFileSync(file)).digest('hex'),before=fingerprint();
async function call(path,body,cookie=''){
  const r=await fetch(base+'/api'+path,{method:body===undefined?'GET':'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-Fatya-Request':'1',Cookie:cookie},body:body===undefined?undefined:JSON.stringify(body)});
  return {status:r.status,data:await r.json(),cookie:r.headers.get('set-cookie')?.split(';')[0]};
}
try{
  artisan('migrate:fresh','--force');
  artisan('fatya:import-sqlite',file);assert.equal(fingerprint(),before,'Original SQLite backup stays unchanged');
  assert.throws(()=>artisan('fatya:import-sqlite',file),/empty store/,'Import refuses to overwrite an existing store');
  await start();
  let r=await call('/auth/login',{phone:customer.phone,password});assert.equal(r.status,200);assert.equal(r.data.id,customer.id);
  const hash=probe("echo json_encode(Illuminate\\Support\\Facades\\DB::table('customer_credentials')->value('password'));");
  assert.ok(hash.startsWith('$argon2id$'),'Legacy scrypt is upgraded to Laravel Argon2id after login');
  const admin=await call('/admin/login',{username:'masal',password:'test-password-123456'});
  r=await call('/admin/state',undefined,admin.cookie);assert.equal(r.data.settings.name,'فاتيا');assert.equal(r.data.products[0].stock[0],1);assert.equal(r.data.categories.length,5);assert.deepEqual(r.data.companies,[]);
  r=await call('/admin/cards/search',{serial:'00000999'},admin.cookie);assert.equal(r.data.card.serial,'00000999');assert.ok(!JSON.stringify(r.data).includes('LEGACY-PRIVATE-CODE'));
  await stop();await start();assert.equal((await call('/auth/login',{phone:customer.phone,password})).status,200);
  console.log('SQLite to MariaDB import preserves catalog, accounts, scrypt passwords, inventory, leading-zero serials and source backup; repeat import is refused.');
}finally{await stop();const resolved=fileURLToPath(new URL('../../',import.meta.url));assert.ok(temp.startsWith(resolved));rmSync(temp,{recursive:true,force:true});}
