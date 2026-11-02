import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const source=readFileSync(new URL('../src/DigitalProvidersManager.js',import.meta.url),'utf8');
const handler=source.slice(source.indexOf('function connectionSaved('),source.indexOf('    function getProvinces'));
function fixture(currentProvider='topup',errors=[]){
 const events=[],state={provider:{value:currentProvider},catalog:{value:{old:true}},balance:{value:null},chosen:{value:{}},publishing:{value:{}},error:{value:''},message:{value:''}};
 const next={catalog:{hash:'new',offers:[]},balance:{balance:'0.000'},errors};
 const fn=Function('run','load','refreshProviderCatalog','api',...Object.keys(state),handler+';return connectionSaved;')(fn=>fn(),async()=>events.push('load'),async(_api,id)=>{events.push('refresh:'+id);return next;},{},...Object.values(state));
 return{events,state,next,fn};
}
const f=fixture();await f.fn({provider:'topup',refresh:true});
assert.deepEqual(f.events,['load','refresh:topup','load']);assert.equal(f.state.catalog.value,f.next.catalog);assert.equal(f.state.balance.value.balance,'0.000');assert.equal(f.state.chosen.value,null);assert.equal(f.state.publishing.value,null);
const other=fixture('rabiaa');await other.fn({provider:'topup',refresh:true});assert.equal(other.state.catalog.value.old,true,'Refreshing TOPUP does not replace the selected Rabiaa catalog');assert.ok(other.events.includes('refresh:topup'));
const disabled=fixture();await disabled.fn({provider:'topup',refresh:false});assert.deepEqual(disabled.events,['load'],'A disabled provider must not trigger a provider request');
const failed=fixture('topup',['تعذّر تحديث الرصيد']);await failed.fn({provider:'topup',refresh:true});assert.match(failed.state.error.value,/تعذّر/);assert.equal(failed.state.message.value,'');
const connection=readFileSync(new URL('../src/DigitalConnectionManager.js',import.meta.url),'utf8');
const save=connection.slice(connection.indexOf('function save(c)'),connection.indexOf('  onMounted'));
const c={id:'topup',version:2,active:true,credential:'fixture-only',reason:'test'},sent=[],editing={value:'topup'},message={value:''};
const call=Function('run','api','refreshStore','load','emit','editing','message',save+';return save;')(fn=>fn(),async(path,body,method)=>sent.push({path,body:{...body},method}),async()=>{},async()=>{},(...event)=>sent.push(event),editing,message);
await call(c);assert.equal(c.credential,'');assert.equal(editing.value,null);assert.deepEqual(sent[1],['saved',{provider:'topup',refresh:true,tokenUpdated:true}]);assert.equal(sent[0].method,'PUT');
console.log('Saved tokens are cleared from the editor; successful token changes refresh catalog and balance immediately, with errors and disabled providers handled.');
