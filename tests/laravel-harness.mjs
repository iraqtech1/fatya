import {spawn,spawnSync} from 'node:child_process';
import {readFileSync} from 'node:fs';
import {parseEnv} from 'node:util';
import {createServer} from 'node:net';
const cwd=new URL('../backend/',import.meta.url),php=process.env.PHP_BIN||'php';
const settings=parseEnv(readFileSync(new URL('../backend/.env',import.meta.url),'utf8'));
const database=process.env.FATYA_TEST_DATABASE;
if(!database||!database.endsWith('_test'))throw Error('Set FATYA_TEST_DATABASE to a dedicated disposable MariaDB database ending in _test.');
export const env={...process.env,...settings,APP_ENV:'testing',APP_DEBUG:'false',DB_CONNECTION:'mariadb',DB_DATABASE:database,ADMIN_USER:'masal',ADMIN_PASSWORD:'test-password-123456',DEV_OTP:'true',SESSION_SECURE_COOKIE:'false',BCRYPT_ROUNDS:'4'};
env.ALLOW_PAYMENT_SIMULATION='true';
for(const key of ['HOST','PORT','USERNAME','PASSWORD'])if(process.env['FATYA_TEST_DB_'+key]!==undefined)env['DB_'+key]=process.env['FATYA_TEST_DB_'+key];
export function artisan(...args){const r=spawnSync(php,['artisan',...args,'--no-ansi'],{cwd,env,encoding:'utf8'});if(r.status!==0)throw Error(r.stdout+r.stderr);return r.stdout;}
export function probe(code){const r=spawnSync(php,['-r',"require 'vendor/autoload.php'; $app = require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); "+code],{cwd,env,encoding:'utf8'});if(r.status!==0)throw Error(r.stdout+r.stderr);return JSON.parse(r.stdout);}
export function confirmPayment(order,amount,transaction='test-'+order,provider='Qi',currency='IQD',event=transaction){return JSON.parse(artisan('fatya:simulate-payment',order,'--amount='+amount,'--provider='+provider,'--currency='+currency,'--transaction='+transaction,'--event='+event).trim());}
let app,port,output='';
export let base;
export async function start(){
  if(!port){const probe=createServer();await new Promise(r=>probe.listen(0,'127.0.0.1',r));port=probe.address().port;await new Promise(r=>probe.close(r));}
  app=spawn(php,['-S','127.0.0.1:'+port,'../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'],{cwd:new URL('../backend/public/',import.meta.url),env,stdio:['ignore','pipe','pipe']});app.stdout.on('data',c=>output+=c);app.stderr.on('data',c=>output+=c);
  base='http://127.0.0.1:'+port;
  for(let i=0;i<100;i++){try{if((await fetch(base+'/api/health')).ok)return;}catch{}if(app.exitCode!==null)throw Error(output);await new Promise(r=>setTimeout(r,100));}
  throw Error('Laravel did not start: '+output);
}
export async function stop(){if(app&&app.exitCode===null){app.kill();await new Promise(r=>app.once('exit',r));}app=undefined;}
