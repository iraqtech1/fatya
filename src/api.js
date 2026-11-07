import {createSnapshotReader} from './state-snapshot.js';
const snapshots=createSnapshotReader((...args)=>fetch(...args));
export const readStateSnapshot=snapshots.read;
export const invalidateStateSnapshot=snapshots.invalidate;
export const connected=import.meta.env?.VITE_DATA_MODE!=='preview'&&typeof window!=='undefined';
export async function readStockUpload(file){
  const body=new FormData();body.append('file',file);
  const response=await fetch('/api/admin/procurement/stock-orders/read',{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','X-Fatya-Request':'1'},body});
  const result=await response.json();if(!response.ok)throw Object.assign(Error(result.error||'تعذّرت قراءة الملف على الخادم'),{status:response.status});
  return result.sheets.map(s=>({name:s.name,raw:s.rows,sourceRows:s.sourceRows}));
}
export async function api(path,body,method=body===undefined?'GET':'POST'){
  const mutation=!['GET','HEAD'].includes(method.toUpperCase());
  if(mutation)snapshots.invalidate();
  try{
    const response=await fetch('/api'+path,{method,credentials:'same-origin',headers:{Accept:'application/json',...(body===undefined?{}:{'Content-Type':'application/json','X-Fatya-Request':'1'})},body:body===undefined?undefined:JSON.stringify(body)});
    const data=await response.json();
    if(!response.ok)throw Object.assign(Error(data.error||'تعذّر الاتصال بالخادم'),{status:response.status});
    return data;
  }finally{if(mutation)snapshots.invalidate();}
}
