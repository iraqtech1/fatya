export function validateInventory(rows,products,existing=new Set(),existingSerials=new Set()){
  const accepted=[],errors=[],seen=new Set(existing),serials=new Set(existingSerials);
  for(let i=0;i<rows.length;i++){
    const row=rows[i],id=Number(row.product_id),value=Number(row.denomination),code=String(row.code??'').trim(),serial=String(row.serial??'').trim();
    const product=products.find(p=>p.id===id),index=product?.values.indexOf(value)??-1;
    let reason='';
    if(!product||index<0)reason='بطاقة أو فئة غير موجودة';
    else if(!code||code.length>256)reason='الكود فارغ أو أطول من المسموح';
    else if(seen.has(code))reason='كود مكرر';
    else if(serial.length>256)reason='السيريل أطول من المسموح';
    else if(serial&&serials.has(serial))reason='سيريل مكرر';
    if(reason){errors.push({row:i+2,reason});continue;}
    seen.add(code);if(serial)serials.add(serial);accepted.push({productId:id,index,value,code,serial});
  }
  return {accepted,errors};
}
export function findInventoryCard(entries,products,serial){
  const key=serial.trim();
  const entry=entries.find(r=>r.serial===key)||entries.find(r=>!r.serial&&r.code===key);
  const product=entry&&products.find(p=>p.id===entry.productId);
  if(!product)return null;
  return {productId:product.id,name:product.name,value:entry.value,unit:product.unit,serial:entry.serial||entry.code};
}
