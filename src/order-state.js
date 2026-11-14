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
  if(order?.digitalProvider&&order?.fulfillmentStatus==='failed')return 'رفضت الشركة التنفيذ';
  if(order?.fulfillmentStatus==='refunded')return 'تم توثيق استرجاع المبلغ';
  if(order?.digitalProvider&&order?.paymentStatus==='confirmed'&&order?.fulfillmentStatus==='delivered')return 'تم تنفيذ الخدمة';
  if(order?.digitalProvider&&order?.paymentStatus==='confirmed'&&order?.fulfillmentStatus==='processing')return 'الخدمة قيد التنفيذ';
  if(order?.digitalProvider&&order?.fulfillmentStatus==='review')return 'تنفيذ الخدمة يحتاج مراجعة';
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
export const inventoryStateLabel=state=>({available:'متاح',reserved:'محجوز',delivered:'مسلّم',withdrawn:'مسحوب',expired:'منتهي',refunded:'مسترجع',failed:'مرفوض',review:'تحتاج مراجعة',awaiting_payment:'بانتظار الدفع',queued:'بانتظار التنفيذ',processing:'قيد التنفيذ',hold_started:'أُرسل الحجز',held:'محجوز لدى الشركة',purchase_started:'أُرسل الشراء'}[state]||state||'—');
export function allowedOrderStatuses(order){
  const statuses=[order.status];
  if(cancellableOrder(order)||!order.fulfillmentStatus&&order.status!=='مكتمل')statuses.push('ملغي','قيد المراجعة');
  return [...new Set(statuses)];
}
export function paymentReviewLabel(reason){
  if(executionReasons[reason])return executionReasons[reason];
  return ({amount_mismatch:'المبلغ غير مطابق',currency_mismatch:'العملة غير مطابقة',provider_mismatch:'بوابة الدفع غير مطابقة',additional_payment:'دفعة إضافية لنفس الطلب',reservation_expired:'وصل الدفع بعد انتهاء الحجز',reservation_cancelled:'وصل الدفع بعد إلغاء الطلب',reservation_review:'الحجز يحتاج مراجعة',customer_inactive:'الحساب موقوف',allocation_incomplete:'تخصيص المخزون غير مكتمل',legacy_order_without_reservation:'طلب سابق بدون حجز موثّق'}[reason]|| (reason?'دفع يحتاج مراجعة':'—'));
}
export function orderTime(timestamp){
  return Number(timestamp)>0?new Date(Number(timestamp)).toLocaleString('en-GB',{timeZone:'Asia/Baghdad',hour12:false}):'—';
}

export const executionReasons={provider_rejected:'رفض مؤكد من الشركة وفق رمز الرفض المعتمد',connection_changed:'تغير مفتاح الربط قبل التنفيذ',quote_changed:'تغير السعر أو توفر الخدمة قبل الشراء',eligibility_not_confirmed:'لم تتأكد أهلية المستفيد؛ لم يبدأ الشراء',invalid_subscriber_province:'محافظة المشترك غير مقبولة',invalid_hold_evidence:'دليل حجز الشركة غير مكتمل',hold_price_or_expiry_changed:'تغير سعر الحجز أو انتهت صلاحيته',invalid_provider_response:'رد الشركة غير مفهوم',provider_result_uncertain:'لم يتأكد نجاح العملية من رد الشركة',recipient_mismatch:'المستفيد في الرد غير مطابق',missing_provider_reference:'لا يوجد مرجع شركة صالح',purchase_evidence_incomplete:'دليل شراء البطاقة غير مكتمل',premium_delivery_uncertain:'تسليم Premium غير مؤكد',financial_dispatch_already_recorded:'يوجد إرسال سابق؛ مُنعت إعادة العملية',financial_dispatch_not_authorized:'إرسال العملية غير مخوّل',provider_transport_uncertain:'انقطع الاتصال؛ نتيجة الشركة غير مؤكدة',provider_http_error:'وصل رد HTTP غير ناجح؛ لا يثبت رفض الخصم',provider_invalid_response:'وصل رد لا يمكن قراءته',interrupted_execution:'توقف العامل أثناء التنفيذ؛ يلزم فحص الأدلة',provider_result_requires_review:'نتيجة الشركة تحتاج مراجعة الأدلة'};
export const executionReasonLabel=reason=>executionReasons[reason]||(reason?'سبب غير مصنف؛ راجع الأدلة':'—');
export const dispatchOutcomeLabel=outcome=>({dispatch_started:'سُجّلت نية الإرسال؛ لم يُحفظ رد',transport_uncertain:'لا يوجد رد مؤكد',http_error:'وصل رد HTTP غير ناجح',invalid_response:'وصل رد غير مفهوم',response_received:'وصل رد؛ نتيجة التنفيذ تُراجع بصورة مستقلة'}[outcome]||'حالة غير معروفة');

export const dispatchStateLabel=state=>({rejection_confirmed:'رفض مؤكد من الشركة',legacy_unknown:'غير متاح في السجل القديم',not_sent:'لم يُرسل طلب مالي إلى المزود',not_requested:'لم تبدأ مرحلة الطلب المالي',success_confirmed:'نجاح مؤكد بدليل التنفيذ',uncertain:'نتيجة غير محسومة؛ لا يُعاد إرسال طلب مالي'}[state]||'غير متاح في السجل القديم');
