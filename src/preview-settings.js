import {normalizeBrandSettings} from './branding.js';
export const SETTINGS_KEY='masal-store-settings-v1';
export const defaultSettings={name:'فاتيا',lowStock:5,supportEmail:'',supportPhone:'',privacyText:'',aboutText:''};

function cleanSettings(data){
  const settings={...defaultSettings};
  if(!data||typeof data!=='object'||Array.isArray(data))return settings;
  if(typeof data.name==='string'&&data.name.trim()&&data.name.length<=60)settings.name=data.name.trim();
  if(Number.isInteger(data.lowStock)&&data.lowStock>=0&&data.lowStock<=10000)settings.lowStock=data.lowStock;
  for(const [key,max]of [['supportEmail',254],['supportPhone',30],['privacyText',10000],['aboutText',10000]])if(typeof data[key]==='string'&&data[key].length<=max)settings[key]=data[key].trim();
  return normalizeBrandSettings(settings);
}

export function readPreviewSettings(storage){
  try{return cleanSettings(JSON.parse(storage.getItem(SETTINGS_KEY)));}
  catch{return {...defaultSettings};}
}

export function savePreviewSettings(data,storage){
  const settings=cleanSettings(data);
  try{storage.setItem(SETTINGS_KEY,JSON.stringify(settings));}
  catch{throw Error('تعذّر حفظ الإعدادات في المتصفح. اسمح بالتخزين وأعد المحاولة.');}
  return settings;
}

export function subscribePreviewSettings(storage,events,update){
  const listener=event=>{
    if(event.key!==SETTINGS_KEY&&event.key!==null)return;
    if(event.storageArea&&event.storageArea!==storage)return;
    update(readPreviewSettings(storage));
  };
  events.addEventListener('storage',listener);
  return ()=>events.removeEventListener('storage',listener);
}
