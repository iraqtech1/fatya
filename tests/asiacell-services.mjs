import assert from 'node:assert/strict';
import {ASIACELL_SERVICES,isAsiacell,serviceOffers,buildAsiacellCheckout} from '../src/asiacell-services.js';
import {store,createOrder} from '../src/store.js';

const product=store.products.find(p=>p.en==='asiacell');
assert.ok(product);
for(const name of ['آسياسيل','اسياسيل','آسيا سيل','Asiacell'])assert.ok(isAsiacell({name}));
assert.ok(!isAsiacell(store.products.find(p=>p.en==='IRAQCELL FTTH')));
assert.ok(!isAsiacell({name:'زين العراق',en:'Zain'}));
assert.deepEqual(ASIACELL_SERVICES.map(s=>s.id),['cards','direct','bundles']);

const originalStock=[...product.stock];
const cards=buildAsiacellCheckout(product,{service:'cards',index:1,quantity:2,method:'ZainCash'},true);
assert.equal(cards.product,product,'Recharge cards retain the configured catalog product');
assert.equal(cards.denom,1);assert.equal(cards.amount,product.prices[1]*2);
assert.equal(cards.demoOnly,false);assert.equal(cards.telecom.recipient,'');
const direct=buildAsiacellCheckout(product,{service:'direct',index:2,recipient:'+964 770 000 0000',quantity:4},true);
assert.equal(direct.quantity,1,'Direct top-ups apply once to the recipient');
assert.equal(direct.telecom.recipient,'07700000000');
assert.equal(direct.product.values[direct.denom],25000);assert.equal(direct.amount,25000);
assert.equal(direct.demoOnly,true);
const bundle=buildAsiacellCheckout(product,{service:'bundles',index:0,recipient:'٠٧٧٠٠٠٠٠٠٠٠'},true);
assert.equal(bundle.telecom.offerLabel,'باقة RED5');assert.equal(bundle.product.unit,'باقة');
assert.equal(bundle.amount,5000);assert.equal(bundle.telecom.recipient,'07700000000');
for(const service of ['direct','bundles']){
  assert.deepEqual(serviceOffers(product,service,false),[],'Sample services cannot become live offers');
  assert.throws(()=>buildAsiacellCheckout(product,{service,index:0,recipient:'07700000000'},false));
  for(const recipient of ['', '077123', '<script>', '077000000001'])assert.throws(()=>buildAsiacellCheckout(product,{service,index:0,recipient},true));
}
assert.throws(()=>buildAsiacellCheckout(product,{service:'cards',index:99},true));
assert.throws(()=>buildAsiacellCheckout(product,{service:'cards',index:0,quantity:0},true));
assert.throws(()=>buildAsiacellCheckout(product,{service:'cards',index:0,method:'unsupported'},true));
const customer={name:'Preview',phone:'',email:'',previewId:crypto.randomUUID()};
for(const checkout of [direct,bundle]){
  const order=createOrder(checkout.product,checkout.denom,checkout.quantity,customer,checkout.method,'test-'+crypto.randomUUID(),checkout);
  assert.equal(order.service,checkout.telecom.service);
  assert.equal(order.recipient,'07700000000');
  assert.equal(order.offerLabel,checkout.telecom.offerLabel);
  assert.equal(order.price,checkout.amount);assert.equal(order.demoOnly,true);
}
assert.deepEqual(product.stock,originalStock,'Sample services never consume recharge card inventory');
console.log('Asiacell service routing, configured card prices, sample isolation, recipient validation and order details passed.');
