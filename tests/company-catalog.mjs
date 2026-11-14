import assert from 'node:assert/strict';
import {reactive} from 'vue/dist/vue.esm-bundler.js';
import {catalogCompanies,companyDenominations,companyPath,denominationPath} from '../src/company-catalog.js';
const product={id:1,name:'آسياسيل',en:'Asiacell',category:'اتصالات',unit:'د.ع',values:[5000,10000],prices:[5250,11000],stock:[2,0],manufacturerId:'asia',manufacturer:{id:'asia',name:'آسياسيل'},providerServices:[{id:'pub-one',type:'topup',price:'11000',title:'تعبئة 10000',purchasable:true},{id:'pub-two',type:'topup',price:'11000',title:'خدمة مستقلة بنفس السعر',purchasable:true}]};
const second={...product,id:2,name:'كارت آخر',providerServices:[],values:[5000],prices:[5500]};
const korek={...product,id:3,name:'كورك',en:'Korek',manufacturerId:'korek',manufacturer:{id:'korek',name:'كورك'},providerServices:[]};
const before=JSON.stringify([product,second,korek]);
const companies=catalogCompanies([product,second,korek]);assert.equal(companies.length,2);assert.equal(companies[0].products.length,2);
assert.equal(catalogCompanies([product,second,korek],'كورك')[0].id,'company:korek');
const groups=companyDenominations(companies[0]),five=groups.find(g=>g.id==='stock:د.ع:5000');assert.equal(five.cards.length,2);assert.equal(five.cards[0].product,product);assert.equal(five.cards[0].index,0);
assert.equal(groups.filter(g=>g.id.startsWith('service:')).length,2,'Equal prices must not merge independent provider services');
assert.equal(groups.find(g=>g.id.endsWith('pub-two')).cards[0].index,1);
assert.equal(JSON.stringify([product,second,korek]),before,'Browsing must not rewrite IDs, stock, prices or company assignments');
assert.equal(catalogCompanies([{...product,manufacturerId:'',manufacturer:null}])[0].id,'product:1','Existing unassigned cards stay reachable');
console.log('Company browsing groups linked cards, preserves physical denominations and independent provider services, and keeps existing IDs and legacy cards.');

assert.equal(catalogCompanies([{...product,image:'cards/product.webp',manufacturer:{...product.manufacturer,image:'cards/company.webp'}}])[0].image,'cards/company.webp','Keep the independent company image already deployed in production');
const independent=catalogCompanies([{...product,image:'company.png',denomImages:['category.png','']}])[0];assert.equal(independent.image,'company.png');const physical=companyDenominations(independent).filter(d=>d.id.startsWith('stock:'));assert.equal(physical[0].image,'category.png');assert.equal(physical[1].image,'','Missing category images must not repeat company logos');


const root={id:'asia',name:'شركة',image:'company.png',active:true},branch={id:'b1',parentId:'asia',name:'فرع',active:true,translations:{en:{name:'Branch'}}},leaf={id:'b2',parentId:'b1',name:'فرع فرعي',active:true};
const tree=catalogCompanies([{...product,manufacturerId:'b2',manufacturerPath:[root,branch,leaf]}],'','en');
assert.equal(tree.length,1);assert.equal(tree[0].products.length,0);
assert.equal(tree[0].children[0].name,'Branch');
assert.equal(tree[0].children[0].children[0].products[0].id,product.id);
assert.deepEqual(companyPath(tree,'company:b2').map(node=>node.id),['company:asia','company:b1','company:b2']);
assert.equal(companyDenominations(tree[0]).length,0,'Sub-branch categories stay inside the branch');
assert.ok(companyDenominations(tree[0].children[0].children[0]).length>0);
assert.equal(catalogCompanies([{...product,manufacturerPath:[root,{...branch,active:false},leaf]}]).length,0);
assert.equal(catalogCompanies([{...product,manufacturerPath:[root,branch,leaf]}],'فرع فرعي').length,1);

const bundles={...product,providerServices:[{id:'first',type:'bundle',title:'Weekly',price:'5250.000',purchasable:true,groupPath:[{remote_id:'g1',name:'Internet'},{remote_id:'g2',name:'Weekly packages'}]},{id:'second',type:'bundle',title:'Monthly',price:'10500.000',purchasable:true,groupPath:[{remote_id:'g3',name:'Calls'}]}]};
const nested=companyDenominations(catalogCompanies([bundles])[0]);
const internet=nested.find(node=>node.name==='Internet');assert.equal(internet.offerCount,1);assert.equal(internet.children[0].name,'Weekly packages');
assert.equal(internet.children[0].children[0].cards[0].name,'Weekly');
assert.equal(denominationPath(nested,internet.children[0].id).length,2);
assert.deepEqual(internet.children.flatMap(group=>group.children.flatMap(leaf=>leaf.cards.map(card=>card.name))),['Weekly'],'Opening a package contains only its own offers');
