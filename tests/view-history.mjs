import assert from 'node:assert/strict';
import {createViewHistory} from '../src/view-history.js';
import {useReportFlow} from '../src/report-flow.js';
import {nextTick} from 'vue/dist/vue.esm-bundler.js';

const clone=v=>JSON.parse(JSON.stringify(v));
const settle=async()=>{for(let n=0;n<12;n++){await Promise.resolve();await nextTick();}};
function fixture(path='/admin/reports'){
 let position=4;const stack=new Map([[4,{position,path,views:{},step:''}]]),actions=[];
 const adapter={read:()=>stack.get(position),seed:views=>{stack.get(position).views=clone(views);},async write(views,step){for(const key of stack.keys())if(key>position)stack.delete(key);const entry={position:position+1,path:stack.get(position).path,views:clone(views),step};stack.set(++position,entry);actions.push('push');await history.restore();},async go(delta){assert.ok(stack.has(position+delta),'Never navigate outside known history in this test');position+=delta;actions.push(delta);await history.restore();},error:e=>{throw e;}};
 const history=createViewHistory(adapter);return {history,adapter,stack,actions,get position(){return position;}};
}
const f=fixture(),flow=useReportFlow({can:()=>true,request:async body=>({rows:[],totals:{rows:0},page:body.page,pages:1}),download:async()=>assert.fail('History must never export or submit')});
f.history.register({key:'reports',enabled:()=>true,read:()=>({path:flow.path.value.map(n=>n.id)}),restore:s=>flow.restorePath(s.path)});await settle();
await flow.open(flow.cards.value[0]);f.history.changed();await settle();assert.equal(f.position,5);
await flow.open(flow.visibleCards.value[0]);f.history.changed();await settle();assert.equal(f.position,6);
await f.adapter.go(-1);assert.deepEqual(flow.path.value.map(n=>n.id),['stock']);assert.equal(f.position,5);
await f.adapter.go(1);assert.equal(flow.type.value,'inventory','Forward restores the report, never a write operation');
flow.back();f.history.changed();await settle();assert.equal(f.position,5,'An in-page Back reuses history instead of inserting a loop');
await f.adapter.go(-1);assert.equal(flow.path.value.length,0,'Browser Back returns to report cards before leaving the report page');
await f.adapter.go(1);assert.equal(flow.path.value[0].id,'stock');
const count=f.actions.length;f.history.changed();await settle();assert.equal(f.actions.length,count,'Restoring does not create duplicate entries');

let card={company:'',denomination:''},dialog=[];
const c=fixture('/app'),active={value:true};
c.history.register({key:'catalog',enabled:()=>active.value,read:()=>card,restore:s=>{card=s;}});
c.history.register({key:'dialogs',enabled:()=>true,read:()=>dialog,restore:s=>{dialog=s;}});await settle();
for(const state of [{company:'one',denomination:''},{company:'one',denomination:'5000'}]){card=state;c.history.changed();await settle();}
dialog=['purchase'];c.history.changed();await settle();
await c.adapter.go(-1);assert.deepEqual(dialog,[]);assert.equal(card.denomination,'5000','Closing a card returns to its exact denomination list');
await c.adapter.go(-1);assert.equal(card.company,'one');assert.equal(card.denomination,'');
await c.adapter.go(-1);assert.equal(card.company,'','Company and denomination levels unwind separately');
await c.adapter.go(1);await c.adapter.go(1);assert.equal(card.denomination,'5000');
assert.ok(!JSON.stringify([...c.stack.values()]).includes('password'));

const deep=fixture();let path={path:['stock','inventory']};const direct=deep.history.register({key:'reports',enabled:()=>true,read:()=>path,restore:s=>{path=s;}});await settle();
direct.back({path:['stock']});await settle();assert.deepEqual(path.path,['stock']);assert.equal(deep.position,4,'A direct/deep link falls back locally, never to an unrelated homepage');
assert.equal(deep.actions.length,0);
const reloaded=createViewHistory(deep.adapter);reloaded.register({key:'reports',enabled:()=>true,read:()=>({path:[]}),restore:s=>{path=s;}});await settle();assert.deepEqual(path.path,['stock'],'State is restored from the current history entry after remount/reload');

const conditional=fixture('/app');let parent={page:'home'},child={view:'menu'};
conditional.history.register({key:'store',enabled:()=>true,read:()=>parent,restore:s=>{parent=s;}});await settle();
parent={page:'account'};
const childRegistration=conditional.history.register({key:'account',enabled:()=>parent.page==='account',read:()=>child,restore:s=>{child=s;}});
conditional.history.changed();await settle();assert.equal(conditional.position,5,'Mounting a nested page cannot overwrite its parent history entry');
child={view:'privacy'};conditional.history.changed();await settle();await conditional.adapter.go(-1);assert.equal(child.view,'menu');await conditional.adapter.go(-1);assert.equal(parent.page,'home');childRegistration.dispose();
parent={page:'account'};child={view:'menu'};conditional.history.register({key:'account',enabled:()=>parent.page==='account',read:()=>child,restore:s=>{child=s;}});conditional.history.changed();await settle();assert.equal(child.view,'menu','A newly opened page must not inherit a stale hidden subview');assert.equal(conditional.position,5);

let release;
const slow=useReportFlow({can:()=>true,request:()=>new Promise(r=>{release=r;}),download:()=>assert.fail()});
await slow.open(slow.cards.value[0]);const loading=slow.open(slow.visibleCards.value[0]);
await slow.restorePath(['stock']);release({rows:[{id:'late'}],totals:{rows:1},page:1,pages:1});await loading;
assert.equal(slow.type.value,'');assert.equal(slow.result.value,null,'A late response cannot restore the report that was left');assert.equal(slow.busy.value,false);
await slow.restorePath(['forbidden']);assert.equal(slow.path.value.length,0,'Restored report IDs are checked against visible permissions');
console.log('Browser/in-page Back, Forward, nested catalog/dialog levels, direct links, remounts, duplicate suppression and stale report responses pass.');
