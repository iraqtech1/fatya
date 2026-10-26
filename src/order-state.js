// Display decisions never authorize fulfillment; Laravel rechecks every action.
export function denominationAvailable(product,index,connected=true){
  return !connected||product?.availability?.[index]===true;
}
export function deliveredOrder(order,connected=true){
  return connected?order?.paymentStatus==='confirmed'&&order?.fulfillmentStatus==='delivered'&&order?.codesAvailable===true:order?.status==='مكتمل';
}
export function cancellableOrder(order,now=Date.now()){
  return order?.paymentStatus==='pending'&&order?.fulfillmentStatus==='reserved'&&Number(order.reservationExpiresAt)>now;
}
export function orderStateLabel(order,now=Date.now()){
  if(deliveredOrder(order))return 'تم التسليم';
  if(order?.paymentStatus==='review')return 'دفع يحتاج مراجعة';
  if(order?.fulfillmentStatus==='expired'||order?.fulfillmentStatus==='reserved'&&Number(order.reservationExpiresAt)<=now)return 'حجز منتهي';
  if(order?.fulfillmentStatus==='cancelled')return 'ملغي';
  if(order?.fulfillmentStatus==='reserved')return 'بانتظار الدفع';
  return order?.status||'قيد المراجعة';
}
export function paymentStateLabel(order){
  if(order?.paymentStatus==='pending'&&!cancellableOrder(order))return 'لم يتم الدفع';
  return {pending:'بانتظار الدفع',confirmed:'دفع مؤكد',review:'دفع يحتاج مراجعة'}[order?.paymentStatus]||'لا يوجد تأكيد دفع';
}
export const inventoryStateLabel=state=>({available:'متاح',reserved:'محجوز',delivered:'مسلّم'}[state]||state||'—');
export function allowedOrderStatuses(order){
  const statuses=[order.status];
  if(cancellableOrder(order)||!order.fulfillmentStatus&&order.status!=='مكتمل')statuses.push('ملغي','قيد المراجعة');
  return [...new Set(statuses)];
}
export function paymentReviewLabel(reason){
  return ({amount_mismatch:'المبلغ غير مطابق',currency_mismatch:'العملة غير مطابقة',provider_mismatch:'بوابة الدفع غير مطابقة',additional_payment:'دفعة إضافية لنفس الطلب',reservation_expired:'وصل الدفع بعد انتهاء الحجز',reservation_cancelled:'وصل الدفع بعد إلغاء الطلب',reservation_review:'الحجز يحتاج مراجعة',customer_inactive:'الحساب موقوف',allocation_incomplete:'تخصيص المخزون غير مكتمل',legacy_order_without_reservation:'طلب سابق بدون حجز موثّق'}[reason]|| (reason?'دفع يحتاج مراجعة':'—'));
}
export function orderTime(timestamp){
  return Number(timestamp)>0?new Date(Number(timestamp)).toLocaleString('en-GB',{timeZone:'Asia/Baghdad',hour12:false}):'—';
}
