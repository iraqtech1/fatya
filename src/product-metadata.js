export const catalogLocales=[{id:'ar',label:'العربية'},{id:'ku',label:'کوردی'},{id:'en',label:'English'}];
export const importFieldOptions=[{key:'pin',label:'رمز الشحن / التفعيل — PIN Code'},{key:'expiry',label:'تاريخ الانتهاء — Expiry Date'},{key:'serial',label:'الرقم التسلسلي — Serial Number'},{key:'cvc',label:'رمز التحقق — CVC / CVV'},{key:'reference',label:'الرقم المرجعي — Reference'}];
export const importPolicy=(p,denom)=>({pin:true,expiry:true,serial:false,cvc:false,reference:false,...p?.importFields,...p?.denomImportFields?.[denom]});
export function prepareCatalogDraft(d,{category=false,position=0}={}){
 const count=category?0:d.values.length;
 if(!category){d.displayOrder??=(position+1)*10;d.importFields={...importPolicy(d),...(!d.id&&!d.importFields?{serial:true}:{})};
 for(const key of ['denomNames','denomDescriptions','denomImportCodes','denomSupplierIds'])d[key]=Array.from({length:count},(_,i)=>d[key]?.[i]||'');d.denomImportFields=Array.from({length:count},(_,i)=>d.denomImportFields?.[i]??null);d.denomOrder=Array.from({length:count},(_,i)=>d.denomOrder?.[i]??(i+1)*10);}
 const translations={};for(const {id} of catalogLocales){const r=d.translations?.[id]||{},ar=id==='ar';translations[id]={name:r.name??(ar?d.name||'':''),description:r.description??(ar?d.tag||'':''),image:r.image??(ar?d.image||'':'')};
 if(!category)for(const key of ['denomNames','denomDescriptions','denomImages'])translations[id][key]=Array.from({length:count},(_,i)=>r[key]?.[i]??(ar?d[key]?.[i]||'':''));}
 d.translations=translations;return d;
}
export function syncArabicDraft(d,{category=false}={}){const ar=d.translations.ar;d.name=ar.name.trim();d.image=ar.image||'';if(!d.en)d.en=[...(d.translations.en.name||d.name)].slice(0,category?60:24).join('');if(!category){d.tag=[...ar.description].slice(0,40).join('');for(const key of ['denomNames','denomDescriptions','denomImages'])d[key]=[...ar[key]];}}
export function presentProduct(p,locale='ar'){
 const ar=p.translations?.ar||{},r=p.translations?.[locale]||{};
 const shown={...p,name:r.name||ar.name||p.name,tag:r.description||ar.description||p.tag,image:r.image||ar.image||p.image};
 if(p.providerServices)shown.providerServices=p.providerServices.map(offer=>{const ar=offer.translations?.ar||{},translated=offer.translations?.[locale]||{};return {...offer,title:translated.title||ar.title||offer.title,description:translated.description||ar.description||offer.description,images:translated.image?[translated.image]:ar.image?[ar.image]:offer.images};}).sort((a,b)=>(a.displayOrder||0)-(b.displayOrder||0));
 for(const key of ['denomNames','denomDescriptions','denomImages'])shown[key]=p.values.map((_,i)=>r[key]?.[i]||ar[key]?.[i]||p[key]?.[i]||'');return shown;
}
export function presentProducts(products,locale='ar'){return products.map((p,i)=>({...presentProduct(p,locale),displayOrder:p.displayOrder??(i+1)*10})).sort((a,b)=>a.displayOrder-b.displayOrder);}
export function presentCategory(c,locale='ar'){const ar=c?.translations?.ar||{},r=c?.translations?.[locale]||{};return{...c,name:r.name||ar.name||c?.name||'',description:r.description||ar.description||'',image:r.image||ar.image||c?.image||''};}
export function denominationOptions(p){return (p?.values||[]).map((value,index)=>({value,index,name:p.denomNames?.[index]||'',description:p.denomDescriptions?.[index]||'',order:p.denomOrder?.[index]??(index+1)*10})).sort((a,b)=>a.order-b.order);}
