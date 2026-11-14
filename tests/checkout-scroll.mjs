import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {computed,ref,watch,nextTick,reactive,effectScope} from 'vue/dist/vue.esm-bundler.js';
import {revealCheckoutReceipt} from '../src/checkout-scroll.js';

const calls=[],main={scrollTop:940,scrollTo(o){this.scrollTop=o.top;calls.push('main');}};
globalThis.document={querySelector:selector=>selector==='.app-shell>main'?main:null};
globalThis.window={scrollY:210,scrollTo(o){this.scrollY=o.top;calls.push('window');}};
const source=readFileSync(new URL('../src/PaymentPreview.js',import.meta.url),'utf8').replace(/^import .*;$/gm,'').replace('export default','return');
const component=Function('computed','ref','watch','nextTick','revealCheckoutReceipt','ArrowRight','Check','ShieldCheck','t','language','presentProduct','connected','store','limitPhoneInput',source)(computed,ref,watch,nextTick,revealCheckoutReceipt,{},{},{},s=>s,ref('ar'),p=>p,true,{orders:[]},s=>s);
const scope=effectScope(),props=reactive({receipt:'',checkout:{product:{values:[10000]},denom:0,amount:11000}});
const view=scope.run(()=>component.setup(props));
view.receiptHeading.value={focus(options){assert.equal(options.preventScroll,true);calls.push('focus');}};
await nextTick();assert.equal(main.scrollTop,940,'Typing in the payment form must retain its position');
props.receipt='ORDER-fixture';await nextTick();await nextTick();
assert.equal(main.scrollTop,0,'Receipt resets the mobile nested scroller');assert.equal(window.scrollY,0,'Receipt also resets web and Safari viewport scroll');assert.deepEqual(calls,['focus','main','window']);
main.scrollTop=30;props.checkout.amount=12000;await nextTick();assert.equal(main.scrollTop,30,'Store updates do not keep pulling a reader to the top');
props.receipt='';await nextTick();assert.equal(main.scrollTop,30);
scope.stop();assert.doesNotThrow(()=>revealCheckoutReceipt(null,{doc:{},win:{}}));
console.log('Payment receipt waits for rendering, restores mobile and window scroll, focuses its heading and does not reset while editing or reading.');

const submitted=[],phoneProps=reactive({receipt:'',checkout:{product:{name:'Company',values:[5000],digitalOffers:{0:{provider:'topup',type:'topup',title:'Direct'}}},denom:0,amount:5250,method:'Manual'}});
const reviewScope=effectScope(),reviewUi=reviewScope.run(()=>component.setup(phoneProps,{emit:(...args)=>submitted.push(args)}));
let opened=0;reviewUi.reviewDialog.value={showModal(){opened++;},close(){}};
reviewUi.digital.value.mobile='07700000000';await reviewUi.reviewPayment();assert.equal(opened,1);assert.equal(submitted.length,0,'Reviewing the number never submits an order');
assert.equal(reviewUi.review.value.confirmMobile,'07700000000');reviewUi.digital.value.mobile='07800000000';reviewUi.confirmReview();
assert.equal(submitted.length,1);assert.equal(submitted[0][1].mobile,'07700000000');assert.equal(submitted[0][1].confirmMobile,'07700000000','Submission uses the number actually reviewed');
reviewUi.confirmReview();assert.equal(submitted.length,1,'Repeated confirmation cannot submit a second request');
await reviewUi.reviewPayment();reviewUi.cancelReview();assert.equal(submitted.length,1);reviewScope.stop();
