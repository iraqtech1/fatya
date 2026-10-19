export const PREVIEW_ORDERS_KEY='fatya-preview-orders-v1';

export function readPreviewOrders(storage){
  try{
    const rows=JSON.parse(storage?.getItem(PREVIEW_ORDERS_KEY)||'[]');
    if(!Array.isArray(rows))return [];
    return rows.filter(o=>o&&typeof o.id==='string'&&typeof o.name==='string'&&typeof o.date==='string'&&Number.isFinite(o.price)&&Number.isFinite(o.value)&&Number.isInteger(o.quantity)&&o.quantity>0&&(o.previewId||o.customerId||o.phone||o.email));
  }catch{return [];}
}

export function savePreviewOrders(storage,orders){
  if(!storage)return;
  storage.setItem(PREVIEW_ORDERS_KEY,JSON.stringify(orders));
}
