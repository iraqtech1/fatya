import assert from 'node:assert/strict';
import {ref,markRaw,effectScope} from 'vue/dist/vue.esm-bundler.js';
import {useStockOrderPreview} from '../src/stock-order-preview.js';
const scope=effectScope();
scope.run(()=>{
  const supplier=ref('Supplier'),source=ref('Invoice'),city=ref('Baghdad'),revision=ref(2),lines=ref([{id:'one',productId:1,denom:0,cost:'100',expiry:'',skipHeader:true,pinColumn:0,serialColumn:1,expiryColumn:2,paste:'',raw:markRaw([['pin'],['0001']])}]);
  const p=useStockOrderPreview({supplier,source,city,lines,revision});
  const mutations=[()=>supplier.value+='x',()=>source.value+='x',()=>city.value+='x',()=>revision.value++,()=>lines.value[0].productId++,()=>lines.value[0].denom++,()=>lines.value[0].cost='200',()=>lines.value[0].expiry='2027-01-01',()=>lines.value[0].skipHeader=false,()=>lines.value[0].pinColumn=3,()=>lines.value[0].serialColumn=-1,()=>lines.value[0].expiryColumn=-1,()=>lines.value[0].paste='new input',()=>lines.value[0].raw=markRaw([['0002']]),()=>lines.value.push({...lines.value[0],id:'two'}),()=>lines.value.pop()];
  for(const mutate of mutations){const t=p.begin();assert.equal(p.accept({quantity:1,costTotal:'100.000'},t),true);p.exclude.value=true;assert.ok(p.requestId.value);mutate();assert.equal(p.preview.value,null,'An edited draft must lose approval synchronously');assert.equal(p.exclude.value,false);assert.equal(p.requestId.value,'');assert.equal(p.accept({quantity:1},t),false,'A late response must not resurrect an obsolete preview');}
  let t=p.begin();p.accept({quantity:1},t);const id=p.requestId.value;p.exclude.value=true;assert.equal(p.requestId.value,id,'Exclusion consent is not a draft change');assert.ok(p.preview.value);
  t=p.begin();lines.value[0].cost='300';lines.value[0].cost='200';assert.equal(p.accept({quantity:1},t),false,'Even editing and reverting invalidates an in-flight request');
  p.accept({quantity:1},p.begin());lines.value[0].cost='400';assert.match(p.previewNotice.value,/معاينة جديدة/);
});
scope.stop();
console.log('Stock previews invalidate synchronously on all editable fields, file replacement and revision changes; late responses cannot restore them.');
