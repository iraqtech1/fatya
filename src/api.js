export const connected=import.meta.env?.VITE_DATA_MODE!=='preview'&&typeof window!=='undefined';
export async function api(path,body,method=body===undefined?'GET':'POST'){
  const response=await fetch('/api'+path,{method,credentials:'same-origin',headers:{Accept:'application/json',...(body===undefined?{}:{'Content-Type':'application/json','X-Fatya-Request':'1'})},body:body===undefined?undefined:JSON.stringify(body)});
  const data=await response.json();
  if(!response.ok)throw Object.assign(Error(data.error||'تعذّر الاتصال بالخادم'),{status:response.status});
  return data;
}
