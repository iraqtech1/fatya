import assert from 'node:assert/strict';
import {loadProviderBalances} from '../src/provider-balances.js';
let calls=[];const status={connections:[{id:'topup',configured:true,balanceSupported:true,balance:'1250.000'},{id:'rabiaa',configured:true,balanceSupported:true}]};
const cards=await loadProviderBalances(async p=>{calls.push(p);return p.endsWith('/digital')?status:{balance:'0.000'};});
assert.equal(cards[0].balance,'0.000','A real zero is displayed');assert.equal(cards[1].balance,null,'Never use TOPUP balance for Rabiaa');assert.match(cards[1].note,/غير متاح/);assert.deepEqual(calls,['/admin/procurement/digital','/admin/procurement/digital/balance']);
const stale=await loadProviderBalances(async p=>{if(p.endsWith('/digital'))return status;throw Error('timeout');});assert.equal(stale[0].balance,'1250.000');assert.match(stale[0].note,/محفوظ/);
const disabled=await loadProviderBalances(async p=>{assert.ok(p.endsWith('/digital'));return{connections:[{id:'topup',configured:false,balanceSupported:true}]};});assert.equal(disabled[0].balance,null);assert.match(disabled[0].note,/إعداد الربط/);
const failed=await loadProviderBalances(async()=>{throw Error('offline');});assert.equal(failed[0].balance,null);assert.match(failed[0].note,/تعذّر/);
console.log('Provider balance cards preserve real zero, distinguish cached/error/unconfigured states, and never substitute TOPUP balance for Rabiaa.');
