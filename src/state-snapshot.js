// Validators are memory-only: never persist private responses or share identities.
export function createSnapshotReader(fetcher){
  const validators=new Map();let generation=0;
  function invalidate(){generation++;validators.clear();}
  async function read(path){
    const current=generation,etag=validators.get(path);
    const response=await fetcher('/api'+path,{method:'GET',credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json',...(etag?{'If-None-Match':etag}:{})}});
    if(current!==generation)return read(path);
    if(response.status===304){if(!etag)throw Error('تعذّر تحديث البيانات');return null;}
    let data;
    try{data=await response.json();}catch{throw Object.assign(Error('تعذّر الاتصال بالخادم'),{status:response.status});}
    if(current!==generation)return read(path);
    if(!response.ok){validators.delete(path);throw Object.assign(Error(data.error||'تعذّر الاتصال بالخادم'),{status:response.status});}
    const next=response.headers.get('ETag');if(next)validators.set(path,next);else validators.delete(path);
    return data;
  }
  return {read,invalidate};
}
