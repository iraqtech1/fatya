// Fetch secrets only on the owner's explicit copy action, never in shared state.
export async function copyOrderCodes(order,request,clipboard){
  if(!order?.id)throw Error('الطلب غير موجود');
  const result=await request('/orders/'+encodeURIComponent(order.id)+'/codes');
  if(!Array.isArray(result.codes)||!result.codes.length||result.codes.some(c=>typeof c.code!=='string'||!c.code))throw Error('أكواد الطلب غير متاحة');
  if(!clipboard?.writeText)throw Error('النسخ غير متاح في هذا المتصفح');
  await clipboard.writeText(result.codes.map(c=>c.detailed||c.cvc||c.reference||c.expiry?['PIN: '+c.code,...(c.serial?['Serial: '+c.serial]:[]),...(c.expiry?['Expiry: '+c.expiry]:[]),...(c.cvc?['CVC / CVV: '+c.cvc]:[]),...(c.reference?['Reference: '+c.reference]:[])].join('\n'):c.code).join(result.codes.some(c=>c.detailed||c.cvc||c.reference||c.expiry)?'\n\n':'\n'));
}
