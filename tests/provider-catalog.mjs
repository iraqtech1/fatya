import assert from 'node:assert/strict';
import {groupProviderCatalog,refreshProviderCatalog} from '../src/provider-catalog.js';
const rows=[{type:'topup',providerId:'1',parentId:null,title:'Asiacell',cost:null,available:false},{type:'topup',providerId:'2',parentId:'1',title:'5000',cost:'4500.000',available:true},{type:'bundle',providerId:'1',parentId:null,title:'Internet',cost:null,available:false},{type:'bundle',providerId:'2',parentId:'1',title:'Weekly',cost:'3000.000',available:true}];
const groups=groupProviderCatalog(rows);assert.equal(groups.length,2);assert.equal(groups[0].title,'Asiacell');assert.equal(groups[1].title,'Internet');assert.equal(groups[0].rows.length,2);assert.equal(groups[1].rows[1].type,'bundle');
const calls=[];const result=await refreshProviderCatalog(async(path)=>{calls.push(path);return path.endsWith('/balance')?{balance:'0.000',currency:'IQD'}:{offers:rows};},'topup');
assert.equal(calls.length,2);assert.equal(result.balance.balance,'0.000');assert.deepEqual(result.catalog.offers,rows);assert.deepEqual(result.errors,[]);
const partial=await refreshProviderCatalog(async path=>{if(path.endsWith('/balance'))throw Error('Fixture offline');return {offers:rows};},'topup');assert.equal(partial.catalog.offers.length,4);assert.equal(partial.balance,null);assert.equal(partial.errors.length,1);
const rabiaaCalls=[];await refreshProviderCatalog(async(path,body)=>{rabiaaCalls.push({path,body});return {offers:[]};},'rabiaa','2');assert.equal(rabiaaCalls.length,1);assert.equal(rabiaaCalls[0].body.provinceId,'2');
console.log('Provider refresh fetches catalog and balance together, preserves zero and partial results, groups service types independently and never invents Rabiaa balance.');

const providerPaths=groupProviderCatalog([{type:'bundle',providerId:'71',groupPath:[{remote_id:'g1',name:'Internet'},{remote_id:'g2',name:'Weekly'}],title:'Bundle A'},{type:'bundle',providerId:'72',groupPath:[{remote_id:'g3',name:'Calls'}],title:'Bundle B'}]);
assert.equal(providerPaths[0].title,'Internet / Weekly');assert.equal(providerPaths[0].rows.length,1);assert.equal(providerPaths[1].title,'Calls');
