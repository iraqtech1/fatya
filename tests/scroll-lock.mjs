import assert from 'node:assert/strict';
import {installScrollLock,popupSelector} from '../src/scroll-lock.js';
const values=new Map([['position','relative'],['top','3px']]),attrs=new Set(),listeners=new Map(),scrolls=[];let elements=[];
const style={getPropertyValue:k=>values.get(k)||'',getPropertyPriority:()=>'',setProperty:(k,v)=>values.set(k,v),removeProperty:k=>values.delete(k)};
const doc={body:{style},documentElement:{setAttribute:k=>attrs.add(k),removeAttribute:k=>attrs.delete(k)},querySelectorAll:()=>elements};
const win={scrollX:0,scrollY:420,addEventListener:(k,v)=>listeners.set(k,v),removeEventListener:k=>listeners.delete(k),scrollTo:p=>scrolls.push(p)};
class Observer{observe(){}disconnect(){}}
const lock=installScrollLock({win,doc,Observer}),visible={getClientRects:()=>[{}]},hidden={getClientRects:()=>[]};
assert.equal(attrs.size,0);elements=[visible];lock.sync();assert.equal(values.get('position'),'fixed');assert.equal(values.get('top'),'-420px');assert.ok(attrs.has('data-fatya-scroll-locked'));
let blocked=0;listeners.get('wheel')({cancelable:true,target:{closest:()=>null},preventDefault(){blocked++;}});assert.equal(blocked,1,'Background wheel is blocked');listeners.get('wheel')({cancelable:true,target:{closest:()=>({})},preventDefault(){blocked++;}});assert.equal(blocked,1,'Popup content keeps its own scroll');
elements=[visible,visible];lock.sync();elements=[visible];lock.sync();assert.equal(scrolls.length,0,'Closing one nested popup cannot unlock the other');
elements=[hidden];lock.sync();assert.equal(values.get('position'),'relative');assert.equal(values.get('top'),'3px');assert.deepEqual(scrolls[0],{left:0,top:420,behavior:'instant'});assert.equal(attrs.size,0);
elements=[visible];lock.sync();listeners.get('fatya:routechange')();elements=[];lock.sync();assert.equal(scrolls.length,1,'Route navigation retains control of destination scroll');
elements=[visible];lock.sync();lock.dispose();assert.equal(values.get('position'),'relative');assert.equal(listeners.size,0);
assert.ok(popupSelector.includes('details.searchable-filter[open]'));assert.ok(!popupSelector.includes('details[open]'),'FAQ accordions do not lock the page');
console.log('Popup scroll lock preserves position and styles, handles nested popups, respects route navigation, and releases on cleanup.');
