const key='masal-preview-session-v1';
const pages=['home','cards','orders','support','account'];
function normalize(value){
  if(!value||value.entered!==true)return null;
  const user=value.user;
  if(!user||typeof user.name!=='string'||typeof user.email!=='string'||typeof user.phone!=='string')return null;
  return {entered:true,user:user?{...(typeof user.id==='string'?{id:user.id}:{}),name:user.name,phone:user.phone,email:user.email,...(typeof user.previewId==='string'&&user.previewId?{previewId:user.previewId}:{})}:null,page:pages.includes(value.page)?value.page:'home'};
}
// Device-local preview session; only public identity and navigation are stored.
// Production authentication stays in server-issued HttpOnly cookies.
export function restoreStoreSession(storage){
  try{return normalize(JSON.parse(storage.getItem(key)));}catch{return null;}
}
export function saveStoreSession(storage,value){
  try{const session=normalize(value);if(session)storage.setItem(key,JSON.stringify(session));else storage.removeItem(key);}catch{}
}
