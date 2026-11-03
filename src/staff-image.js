export async function readStaffImage(file){
  if(!['image/png','image/jpeg','image/webp'].includes(file.type)||file.size>5*1024*1024)throw Error('اختر صورة PNG أو JPEG أو WebP حتى 5 ميغابايت');
  const bitmap=await createImageBitmap(file);
  try{const scale=Math.min(1,320/Math.max(bitmap.width,bitmap.height)),canvas=document.createElement('canvas');canvas.width=Math.max(1,Math.round(bitmap.width*scale));canvas.height=Math.max(1,Math.round(bitmap.height*scale));canvas.getContext('2d').drawImage(bitmap,0,0,canvas.width,canvas.height);const value=canvas.toDataURL('image/webp',.85);if(value.length>512000)throw Error('الصورة كبيرة؛ اختر صورة أصغر');return value;}finally{bitmap.close();}
}
