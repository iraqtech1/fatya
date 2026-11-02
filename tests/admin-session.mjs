import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const source=readFileSync(new URL('../src/Dashboard.js',import.meta.url),'utf8');
const fn=source.slice(source.indexOf('async function restoreAdminSession(){'),source.indexOf('\n    async function login(){'));
function fixture(api,refreshStore){const state={sessionPending:{value:true},sessionError:{value:''},authenticated:{value:false}},roles=[];const restore=Function('api','refreshStore','syncSettings','setStoreRole','sessionPending','sessionError','authenticated',fn+';return restoreAdminSession;')(api,refreshStore,()=>{},x=>roles.push(x),state.sessionPending,state.sessionError,state.authenticated);return {...state,roles,restore};}
let acceptSession,acceptState;
const session=new Promise(r=>acceptSession=r),state=new Promise(r=>acceptState=r);
const f=fixture(()=>session,()=>state),pending=f.restore();
assert.equal(f.sessionPending.value,true);assert.equal(f.authenticated.value,false);
acceptSession({});await Promise.resolve();await Promise.resolve();
assert.equal(f.sessionPending.value,true,'Do not show login while authenticated data is still loading');
acceptState({});await pending;assert.equal(f.authenticated.value,true);assert.equal(f.sessionPending.value,false);assert.equal(f.sessionError.value,'');
const denied=fixture(async()=>{throw Object.assign(Error('expired'),{status:401});},async()=>assert.fail('Unauthorized session must not load private state'));
await denied.restore();assert.equal(denied.authenticated.value,false);assert.equal(denied.sessionPending.value,false);assert.equal(denied.sessionError.value,'');
let fail=true;const offline=fixture(async()=>{if(fail)throw Error('offline');},async()=>{});
await offline.restore();assert.equal(offline.authenticated.value,false);assert.equal(offline.sessionError.value,'offline');fail=false;await offline.restore();assert.equal(offline.authenticated.value,true);assert.equal(offline.sessionError.value,'');
assert.ok(source.includes('<AdminLogin v-else-if="!authenticated"'));
console.log('Dashboard waits for session and private state, preserves expired-session login, and retries network errors without flashing login.');
