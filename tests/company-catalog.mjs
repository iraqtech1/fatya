import assert from 'node:assert/strict';
import {reactive} from 'vue/dist/vue.esm-bundler.js';
import {catalogCompanies,companyDenominations} from '../src/company-catalog.js';
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
