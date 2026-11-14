import {denominationOptions} from './product-metadata.js';
import {serviceOffers} from './asiacell-services.js';

export function catalogCompanies(products,query='',locale='ar'){
 const roots=[],nodes=new Map(),search=query.trim().toLowerCase();
 const translated=company=>{const ar=company.translations?.ar||{},row=company.translations?.[locale]||{};return {...company,name:row.name||ar.name||company.name,image:row.image||ar.image||company.image||'',description:row.description||ar.description||''};};
 for(const product of products){
  const path=product.manufacturerPath?.length?product.manufacturerPath:[product.manufacturer?{...product.manufacturer,image:product.manufacturer.image||product.image||''}:{id:product.manufacturerId||'',name:product.name,image:product.image,en:product.en}];
  if(path.some(company=>company.active===false))continue;
  let parent=null;
  for(const raw of path){
   const company=translated(raw),id=company.id?'company:'+company.id:'product:'+product.id;
   let node=nodes.get(id);
   if(!node){node={...company,id,parentId:parent?.id||'',en:company.en||company.name,color:product.color,products:[],children:[]};nodes.set(id,node);(parent?parent.children:roots).push(node);}
   parent=node;
  }
  parent.products.push(product);
 }
 function filter(nodes){return nodes.flatMap(node=>{const children=filter(node.children),matches=!search||[node.name,node.en,...node.products.flatMap(p=>[p.name,p.en,...(p.denomNames||[])])].join(' ').toLowerCase().includes(search);return matches||children.length?[{...node,children:matches?node.children:children}]:[];}).sort((a,b)=>(a.displayOrder||0)-(b.displayOrder||0));}
 return filter(roots);
}
export function companyPath(companies,id){for(const company of companies){if(company.id===id)return [company];const nested=companyPath(company.children||[],id);if(nested.length)return [company,...nested];}return [];}

export function companyDenominations(company){
 const groups=new Map();
 for(const product of company?.products||[]){
  for(const option of denominationOptions(product)){
   if(product.digitalOffers?.[option.index]?.provider==='topup')continue;
   const key='stock:'+product.unit+':'+option.value;
   if(!groups.has(key))groups.set(key,{id:key,name:option.name||String(option.value),unit:product.unit,image:product.denomImages?.[option.index]||'',cards:[]});
   groups.get(key).cards.push({id:'stock:'+product.id+':'+option.index,product,index:option.index,name:product.name,price:product.prices[option.index],image:product.denomImages?.[option.index]||'',kind:'stock'});
  }
  for(const service of ['direct','bundles'])for(const [index,offer] of serviceOffers(product,service,false).entries()){
   const key='service:'+product.id+':'+(offer.publicationId||service+':'+offer.index);
   groups.set(key,{id:key,name:offer.label||String(offer.value),unit:service==='direct'?'شحن مباشر':'باقة',image:offer.image||'',cards:[{id:key,product,index,service,name:offer.label||product.name,price:offer.price,image:offer.image||'',kind:'service'}]});
  }
 }
 return [...groups.values()];
}
