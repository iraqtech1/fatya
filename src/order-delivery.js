// Fetch secrets only on the owner's explicit copy action, never in shared state.
export async function copyOrderCodes(order,request,clipboard){
  if(!order?.id)throw Error('الطلب غير موجود');
  const result=await request('/orders/'+encodeURIComponent(order.id)+'/codes');
  if(!Array.isArray(result.codes)||!result.codes.length||result.codes.some(c=>typeof c.code!=='string'||!c.code))throw Error('أكواد الطلب غير متاحة');
  if(!clipboard?.writeText)throw Error('النسخ غير متاح في هذا المتصفح');
  await clipboard.writeText(result.codes.map(c=>c.code).join('\n'));
}
