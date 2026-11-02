import reference from '../backend/database/seeders/asiacell-import-catalog.json' with {type:'json'};
import {importCode,resolveImportCategory} from './stock-import-formats.js';

const text=value=>String(value||'').toLowerCase().replace(/[\s,،_–—-]/g,'');
const isAsiacell=product=>/asiacell|آسياسيل|اسياسيل/i.test([product.name,product.en,...Object.values(product.translations||{}).map(t=>t.name)].join(' '));

// Resolve only existing, unambiguous denominations. Custom saved identifiers take precedence.
export function withBuiltInImportMappings(saved,products){
 const result=[...saved];
 for(const entry of reference){
  const candidates=[];
  for(const product of products.filter(isAsiacell))for(const [denom,value] of product.values.entries()){
   const names=[product.denomNames?.[denom],...Object.values(product.translations||{}).map(t=>t.denomNames?.[denom]),...(product.values.length===1?[product.name,product.en]:[])];
   if(entry.face!==null?Number(value)===entry.face:names.some(name=>text(name)===text(entry.label)))candidates.push({product,denom,value});
  }
  const scopes=new Map();
  for(const candidate of candidates){const scope=candidate.product.manufacturerId?'manufacturer:'+candidate.product.manufacturerId:'*';if(!scopes.has(scope))scopes.set(scope,[]);scopes.get(scope).push(candidate);}
  for(const [scope,matches] of scopes){
   if(matches.length!==1)continue;
   const {product,value}=matches[0];
   for(const code of entry.codes)if(!result.some(m=>m.code===code&&(m.scope||'*')===scope))result.push({code,scope,productId:String(product.id),value});
  }
 }
 return result;
}

export function resolveImportDraft(code,mappings,products,manufacturerId='',productId='',companies=[]){
 const found=resolveImportCategory(code,mappings,products,manufacturerId);
 if(found){const product=products.find(p=>String(p.id)===String(found.productId)),manufacturers=companies.filter(c=>c.active&&c.role!=='supplier'&&/asiacell|آسياسيل|اسياسيل/i.test(c.name));return{...found,manufacturerId:found.manufacturerId||manufacturerId||(isAsiacell(product)&&manufacturers.length===1?manufacturers[0].id:''),retail:String(product.prices[found.denom]),newValue:'',newName:''};}
 const entry=reference.find(r=>r.codes.includes(importCode(code)));if(!entry)return null;
 const candidates=products.filter(p=>isAsiacell(p)&&(!productId||String(p.id)===String(productId))&&(!manufacturerId||!p.manufacturerId||p.manufacturerId===manufacturerId));
 if(candidates.length!==1)return null;
 const product=candidates[0],manufacturers=companies.filter(c=>c.active&&c.role!=='supplier'&&/asiacell|آسياسيل|اسياسيل/i.test(c.name)),manufacturer=product.manufacturerId||manufacturerId||(manufacturers.length===1?manufacturers[0].id:'');
 const denom=entry.face===null?-1:product.values.findIndex(v=>Number(v)===entry.face);
 return{productId:product.id,manufacturerId:manufacturer,denom,retail:denom<0?'':String(product.prices[denom]),newValue:entry.face===null?'':String(entry.face),newName:entry.label};
}
