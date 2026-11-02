import {denominationOptions} from './product-metadata.js';
import {serviceOffers} from './asiacell-services.js';

export function catalogCompanies(products,query=''){
 const groups=new Map(),search=query.trim().toLowerCase();
 for(const product of products){
  const company=product.manufacturer;
  const id=company?.id?'company:'+company.id:product.manufacturerId?'company:'+product.manufacturerId:'product:'+product.id;
  if(!groups.has(id))groups.set(id,{id,name:company?.name||product.name,en:company?.name||product.en,image:product.image||'',color:product.color,products:[]});
  groups.get(id).products.push(product);
 }
 return [...groups.values()].filter(g=>!search||[g.name,g.en,...g.products.flatMap(p=>[p.name,p.en,...(p.denomNames||[])])].join(' ').toLowerCase().includes(search));
}

export function companyDenominations(company){
 const groups=new Map();
 for(const product of company?.products||[]){
  for(const option of denominationOptions(product)){
   if(product.digitalOffers?.[option.index]?.provider==='topup')continue;
   const key='stock:'+product.unit+':'+option.value;
   if(!groups.has(key))groups.set(key,{id:key,name:option.name||String(option.value),unit:product.unit,image:product.denomImages?.[option.index]||product.image||'',cards:[]});
   groups.get(key).cards.push({id:'stock:'+product.id+':'+option.index,product,index:option.index,name:product.name,price:product.prices[option.index],image:product.denomImages?.[option.index]||product.image||'',kind:'stock'});
  }
  for(const service of ['direct','bundles'])for(const [index,offer] of serviceOffers(product,service,false).entries()){
   const key='service:'+product.id+':'+(offer.publicationId||service+':'+offer.index);
   groups.set(key,{id:key,name:offer.label||String(offer.value),unit:service==='direct'?'شحن مباشر':'باقة',image:offer.image||'',cards:[{id:key,product,index,service,name:offer.label||product.name,price:offer.price,image:offer.image||'',kind:'service'}]});
  }
 }
 return [...groups.values()];
}
