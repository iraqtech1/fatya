export const connected=import.meta.env?.VITE_DATA_MODE!=='preview'&&typeof window!=='undefined';
export async function api(path,body,method=body===undefined?'GET':'POST'){
  const response=await fetch('/api'+path,{method,credentials:'same-origin',headers:body===undefined?{}:{'Content-Type':'application/json'},body:body===undefined?undefined:JSON.stringify(body)});
  const data=await response.json();
  if(!response.ok)throw Error(data.error||'تعذّر الاتصال بالخادم');
  return data;
}
