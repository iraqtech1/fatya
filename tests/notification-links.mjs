import assert from 'node:assert/strict';
import {notificationId,notificationTarget,closeReadNotifications} from '../src/notification-links.js';
const id='1d7d2245-a0ab-490f-a340-d9a990e391ff';
assert.equal(notificationId('https://fixture.invalid/app?notification='+id),id);assert.equal(notificationId('https://fixture.invalid/app?notification=bad'),'');
assert.equal(notificationTarget('/orders?order=ORDER-123'),'/orders?order=ORDER-123');assert.equal(notificationTarget('//external.invalid'),'');assert.equal(notificationTarget('/orders?redirect=https://bad.invalid'),'');
const closed=[];Object.defineProperty(globalThis,'navigator',{configurable:true,value:{serviceWorker:{getRegistrations:async()=>[{getNotifications:async()=>[{tag:id,close:()=>closed.push(id)},{tag:'another',close:()=>closed.push('another')}]}]}}});
await closeReadNotifications([id]);assert.deepEqual(closed,[id]);
console.log('Notification links accept internal destinations only, preserve cold-start IDs and close only the system messages actually read.');
