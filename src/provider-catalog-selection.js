import {groupProviderCatalog} from './provider-catalog.js';
export const providerOfferKey=o=>[o.type,o.providerId,o.provinceId||''].join(':');
export function catalogRows(offers,publications=[]){const pubs=new Map(publications.map(p=>[providerOfferKey({...p.snapshot,providerId:p.providerId}),p]));return groupProviderCatalog(offers).flatMap(g=>g.rows.map(o=>({...o,categoryKey:g.key,categoryTitle:g.title,publication:pubs.get(providerOfferKey(o))||null})));}
export function filterCatalogRows(rows,f){return rows.filter(o=>(!f.type||o.type===f.type)&&(!f.category||o.categoryKey===f.category)&&(!f.published||(f.published==='yes')===!!o.publication?.published)&&(!f.available||(f.available==='yes')===!!o.available)&&(!f.query||[o.title,o.providerId,o.parentId,o.categoryTitle].join(' ').toLowerCase().includes(f.query.toLowerCase())));}
export const selectableOffer=o=>!!o.available||!!o.publication?.published;
export const selectFiltered=rows=>new Set(rows.filter(selectableOffer).map(providerOfferKey));
function mills(value){if(!/^\d{1,16}(\.\d{1,3})?$/.test(String(value??'')))throw Error('أدخل السعر أو الزيادة رقماً موجباً، بثلاث منازل عشرية كحد أقصى');const [a,b='']=String(value).split('.');return BigInt(a)*1000n+BigInt(b.padEnd(3,'0'));}
function money(value){return (value/1000n).toString()+'.'+(value%1000n).toString().padStart(3,'0');}
export function previewCatalogPrice(o,options){let price;
 if(options.priceMode==='existing'){if(o.publication?.retail==null)throw Error('بعض المحدد ليس له سعر بيع محفوظ؛ اختر طريقة التسعير');price=mills(o.publication.retail);}
 else if(options.priceMode==='supplier')price=mills(o.cost);
 else if(options.priceMode==='fixed')price=mills(options.retail);
 else if(options.priceMode==='markup'){const percent=mills(options.percent);if(percent>1000000n)throw Error('نسبة الزيادة من صفر إلى 1000');price=(mills(o.cost)*(100000n+percent)+50000n)/100000n+mills(options.flat);}
 else throw Error('اختر طريقة التسعير');
 if(price<=0n)throw Error('سعر البيع يجب أن يكون أكبر من صفر');return money(price);
}
