import assert from 'node:assert/strict';
import {selectedDockItem} from '../src/dock-selection.js';
const items=['home','orders','cards','support','account'].map(id=>({id}));
for(const item of items)assert.equal(selectedDockItem(items,item.id),item);
assert.equal(selectedDockItem(items,'payment').id,'cards','Checkout stays under Buy a card instead of showing a second Home icon');
assert.equal(selectedDockItem(items,'unknown').id,'home');
console.log('Mobile navigation selection remains consistent on every section and checkout.');
