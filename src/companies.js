export const COMPANY_ROLES={manufacturer:'مصنّعة',supplier:'مجهّزة',both:'مصنّعة ومجهّزة'};

// Existing catalogs do not identify the legal manufacturer or supplier.
// Keep those assignments empty until the administrator chooses a company.
export function upsertCompany(companies,d,makeId=()=>crypto.randomUUID()){
  const old=companies.find(c=>c.id===d.id);
  if(d.id&&!old)throw Error('الشركة غير موجودة');
  const name=typeof d.name==='string'?d.name.trim():'';
  if(!name||name.length>80||!Object.hasOwn(COMPANY_ROLES,d.role)||typeof d.active!=='boolean')throw Error('اكتب اسم الشركة واختر نوعها');
  if(companies.some(c=>c.id!==d.id&&c.name===name))throw Error('اسم الشركة موجود بالفعل');
  const item={id:old?.id||makeId(),name,role:d.role,active:d.active};
  if(old)Object.assign(old,item);else companies.push(item);
  return item;
}

export function validateProductCompanies(companies,product){
  for(const [field,role] of [['manufacturerId','manufacturer'],['supplierCompanyId','supplier']]){
    const id=product[field];
    if(!id)continue;
    const company=companies.find(c=>c.id===id);
    if(!company||company.role!==role&&company.role!=='both')throw Error('اختر شركة مناسبة لنوع البطاقة');
  }
}

export function denominationImage(product,index){
  return product.denomImages?.[index]||(product.values.length===1?product.image:'')||'';
}
