// A device payload is opaque. Preserve its case, punctuation and length.
export function deviceIdentifier(value){
 const text=String(value??'').trim();
 if(!/^[A-Za-z0-9_+/=\-]{6,2048}$/.test(text))throw Error('معرّف الجهاز غير صحيح؛ الصق محتوى QR كاملاً دون اختصاره');
 return text;
}
export function activationRequest(device,pin,key,orderId=''){
 const mac_address=deviceIdentifier(device),active_code=String(pin??'').trim();
 if(!orderId&&!/^[A-Za-z0-9_ .\-]{1,2000}$/.test(active_code))throw Error('أدخل PIN بطاقة التفعيل');
 return {idempotency_key:key,mac_address,...orderId?{order_id:orderId}:{active_code}};
}
export async function readQr(source){
 const {default:jsQR}=await import('jsqr');
 const width=source.videoWidth||source.naturalWidth||source.width,height=source.videoHeight||source.naturalHeight||source.height;
 if(!width||!height)return null;
 const scale=Math.min(1,2000/Math.max(width,height)),canvas=document.createElement('canvas');canvas.width=Math.round(width*scale);canvas.height=Math.round(height*scale);
 const context=canvas.getContext('2d',{willReadFrequently:true});context.drawImage(source,0,0,canvas.width,canvas.height);
 const pixels=context.getImageData(0,0,canvas.width,canvas.height);
 return jsQR(pixels.data,pixels.width,pixels.height,{inversionAttempts:'attemptBoth'})?.data||null;
}
