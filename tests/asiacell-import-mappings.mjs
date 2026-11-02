import assert from 'node:assert/strict';
import reference from '../backend/database/seeders/asiacell-import-catalog.json' with {type:'json'};
import {withBuiltInImportMappings,resolveImportDraft} from '../src/asiacell-import-mappings.js';
import {resolveImportCategory,detectStockFormat} from '../src/stock-import-formats.js';
const faces=reference.filter(r=>r.face!==null).map(r=>r.face);
const bundles=reference.filter(r=>r.face===null);
const products=[{id:'asia',name:'آسياسيل',manufacturerId:'asia-company',values:faces},{id:'bundles',name:'Asiacell bundles',manufacturerId:'asia-company',values:bundles.map((r,i)=>i+1),translations:{en:{denomNames:bundles.map(r=>r.label)}}},{id:'other',name:'زين العراق',values:faces}];
const before=JSON.stringify(products),mappings=withBuiltInImportMappings([],products);
assert.equal(reference.length,28);
for(const entry of reference)for(const code of entry.codes){
 const data=detectStockFormat([['SAMPLE','1','EVS-'+code,'5000','IQD'],['000001','00000011','2030-12-31']]);
 const found=resolveImportCategory(data.categoryCode,mappings,products);
 assert.ok(found,code);assert.equal(found.productId,entry.face===null?'bundles':'asia',code);
}
assert.equal(JSON.stringify(products),before,'Recognition must not add products, prices or denominations');
const saved=[{code:'E5K',scope:'manufacturer:asia-company',productId:'asia',value:10000}];
assert.equal(resolveImportCategory('E5K',withBuiltInImportMappings(saved,products),products).denom,faces.indexOf(10000),'Saved mappings override built-in defaults');
const ambiguous=withBuiltInImportMappings([],[products[0],{...products[0],id:'duplicate'}]);
assert.equal(resolveImportCategory('E5K',ambiguous,products),null,'Ambiguous categories require manual choice');
assert.equal(resolveImportCategory('UNKNOWN',mappings,products),null);
assert.equal(withBuiltInImportMappings([],[products[2]]).length,0,'Asiacell codes never select another network');
console.log('All 28 embedded Asiacell categories recognize their file identifiers; custom mappings and ambiguous/manual cases are preserved.');

const draft=resolveImportDraft('EVS-E10K',[],[{id:1,name:'آسياسيل',values:[5000],prices:[5250]}],'','',[{id:'asia',name:'آسياسيل',role:'manufacturer',active:true}]);
assert.equal(draft.productId,1);assert.equal(draft.denom,-1);assert.equal(draft.newValue,'10000');assert.equal(draft.manufacturerId,'asia');assert.equal(draft.retail,'','New categories require the administrator sale price');
