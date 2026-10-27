import {reactive} from 'vue/dist/vue.esm-bundler.js';
import {api,connected} from './api.js';
export const pushState=reactive({busy:false,enabled:false,error:'',supported:typeof Notification!=='undefined'&&typeof navigator!=='undefined'&&'serviceWorker' in navigator&&'PushManager' in globalThis});
let sdkReady,owner='',generation=0;
function loadSdk(){
  if(!sdkReady)sdkReady=new Promise((resolve,reject)=>{if(window.Takeed)return resolve(window.Takeed);const script=document.createElement('script');script.src='/push/takeed.js';script.onload=()=>resolve(window.Takeed);script.onerror=()=>{sdkReady=null;reject(Error('تعذّر تفعيل الإشعارات حاول لاحقاً'));};document.head.append(script);});
  return sdkReady;
}
let initialized;
async function identify(expectedOwner=owner){
  const config=await api('/push/config');
  if(!config.enabled)throw Error('خدمة الإرسال غير مهيأة');
  const sdk=await loadSdk();
  if(expectedOwner!==owner)throw Error('Account changed');
  // login stores the new identity before init subscribes; do not await it first.
  const login=sdk.login(config.externalId,config.identityHash);
  if(!initialized)initialized=sdk.init({appId:config.appId,apiUrl:'https://control.takeed-iq.com',serviceWorkerPath:'/push/TakeedSDKWorker.js',serviceWorkerScope:'/push/'}).catch(e=>{initialized=null;throw e;});
  await Promise.all([initialized,login]);
  return sdk;
}
export async function disconnectPush(){
  generation++;owner='';pushState.enabled=false;
  if(!connected||!pushState.supported)return;
  const registration=await navigator.serviceWorker.getRegistration('/push/');
  const sub=await registration?.pushManager.getSubscription();
  if(sub)await sub.unsubscribe();
  if(window.Takeed&&initialized)await window.Takeed.logout().catch(()=>{});
}
export async function syncPushCustomer(id){
  if(!connected||!pushState.supported||id===owner)return;
  const ticket=++generation;owner=id||'';
  if(!id){await disconnectPush().catch(()=>{});return;}
  if(Notification.permission!=='granted')return;
  try{const sdk=await identify();if(ticket===generation)pushState.enabled=sdk.User.PushSubscription.optedIn;}catch{pushState.enabled=false;}
}
export async function togglePush(){
  if(pushState.busy||!connected||!pushState.supported)return;
  pushState.busy=true;pushState.error='';
  try{
    if(pushState.enabled){const sdk=await loadSdk();await sdk.User.PushSubscription.optOut();pushState.enabled=false;}
    else {
      // Request permission directly from the user's click, before asynchronous setup.
      const permission=await Notification.requestPermission();
      if(permission!=='granted')throw Error('اسمح بالإشعارات من إعدادات المتصفح');
      const sdk=await identify();await sdk.User.PushSubscription.optIn();await sdk.Notifications.requestPermission();pushState.enabled=sdk.User.PushSubscription.optedIn;
    }
  }catch{pushState.error='تعذّر تفعيل الإشعارات راجع سماح المتصفح وإعدادات الخدمة';}
  finally{pushState.busy=false;}
}
