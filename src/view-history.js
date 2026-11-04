import {watch,onMounted,onBeforeUnmount,nextTick,getCurrentInstance} from 'vue/dist/vue.esm-bundler.js';

const copy=value=>JSON.parse(JSON.stringify(value));
const same=(a,b)=>JSON.stringify(a)===JSON.stringify(b);
let navigation;

// Store only structural navigation, never form fields, credentials or card codes.
export function createViewHistory(adapter){
 const entries=new Map(),views=new Set();
 let applying=0,queued=false,writing=false,dirty=false,sequence=0,ownStep=null,disposed=false;
 function current(){return adapter.read();}
 function snapshot(){return Object.fromEntries([...views].filter(v=>v.enabled()).map(v=>[v.key,copy(v.read())]));}
 function remember(){const c=current();entries.set(c.position,{path:c.path,views:copy(c.views||{})});}
 function seed(){const c=current(),state={...(c.views||{}),...snapshot()};adapter.seed(state);remember();}
 function previous(target,key){const c=current();return [...entries].filter(([position,entry])=>position<c.position&&entry.path===c.path&&(key?same(entry.views[key],target):Object.entries(target).every(([k,v])=>same(entry.views[k],v)))).sort((a,b)=>b[0]-a[0])[0];}
 async function flush(){
  await nextTick();queued=false;if(disposed||applying)return;if(writing){dirty=true;return;}
  const c=current(),state=snapshot();
  if(Object.entries(state).every(([key,value])=>same(c.views?.[key],value)))return;
  const old=previous(state);
  writing=true;
  try{
   if(old)await adapter.go(old[0]-c.position);
   else{ownStep=String(Date.now())+'-'+(++sequence);await adapter.write({...c.views,...state},ownStep);}
  }finally{writing=false;ownStep=null;if(dirty){dirty=false;changed();}}
 }
 function changed(){if(applying||disposed||queued)return;queued=true;Promise.resolve().then(flush).catch(adapter.error||(()=>{}));}
 async function restore(){
  if(disposed)return;
  const c=current();remember();
  if(c.step===ownStep){seed();return;}
  applying++;
  try{
   // Section routing and conditional child mounting settle before restoring views.
   await nextTick();
   for(const view of [...views].sort((a,b)=>(a.key==='dialogs')-(b.key==='dialogs'))){
    if(current().position!==c.position)return;
    if(view.enabled()&&c.views&&Object.hasOwn(c.views,view.key))await view.restore(copy(c.views[view.key]));
   }
   await nextTick();if(current().position===c.position)seed();
  }finally{applying--;}
 }
 function register(view){
  const c=current();
  const restoring=applying||[...views].filter(v=>v.enabled()).every(v=>!Object.hasOwn(c.views||{},v.key)||same(v.read(),c.views[v.key]));
  views.add(view);let mounted=true;
  const ready=(async()=>{
   if(restoring&&view.enabled()&&c.views&&Object.hasOwn(c.views,view.key)){applying++;try{await view.restore(copy(c.views[view.key]));await nextTick();}finally{applying--;}}
   if(mounted&&view.enabled()&&current().position===c.position){adapter.seed({...current().views,[view.key]:copy(view.read())});remember();}
  })();
  ready.catch(adapter.error||(()=>{}));
  return {dispose(){mounted=false;views.delete(view);},back(value){
   const c=current(),old=previous(value,view.key);
   if(old){void adapter.go(old[0]-c.position);return;}
   applying++;Promise.resolve(view.restore(copy(value))).then(nextTick).then(()=>{seed();applying--;}).catch(e=>{applying--;adapter.error?.(e);});
  }};
 }
 return {register,changed,restore,dispose(){disposed=true;views.clear();entries.clear();},get applying(){return applying;}};
}

export function configureViewHistory(router,win=globalThis.window){
 if(!win)return;
 navigation=createViewHistory({
  read:()=>({path:router.currentRoute.value.path,step:router.currentRoute.value.query._view,position:win.history.state?.position??0,views:win.history.state?.fatyaViews}),
  seed:views=>win.history.replaceState({...win.history.state,fatyaViews:views},''),
  write:(views,step)=>router.push({path:router.currentRoute.value.path,query:{...router.currentRoute.value.query,_view:step},hash:router.currentRoute.value.hash,state:{fatyaViews:views}}),
  go:delta=>new Promise(resolve=>{const remove=router.afterEach(()=>{remove();resolve();});router.go(delta);}),
  error:e=>console.error('Navigation could not be restored',e)
 });
 router.afterEach((_to,_from,failure)=>{if(!failure)void navigation.restore();});
}

export function useViewHistory(key,read,restore,{enabled=()=>true}={}){
 if(!getCurrentInstance())return {back:value=>restore(copy(value))};
 let registration;
 onMounted(()=>{if(navigation)registration=navigation.register({key,read,restore,enabled});});
 const stop=watch(()=>enabled()?JSON.stringify(read()):null,()=>{if(registration&&enabled())navigation.changed();},{flush:'post'});
 onBeforeUnmount(()=>{stop();registration?.dispose();});
 return {back(value){if(registration)registration.back(value);else return restore(copy(value));}};
}

export function installDialogHistory(doc=globalThis.document,Observer=globalThis.MutationObserver){
 if(!navigation||!doc?.body||!Observer)return {dispose(){}};
 const ids=new WeakMap();let serial=0;
 const dialogs=()=>[...doc.querySelectorAll('dialog')];
 const id=element=>{if(!ids.has(element))ids.set(element,element.id||'dialog-'+(++serial));return ids.get(element);};
 const view={key:'dialogs',enabled:()=>true,read:()=>dialogs().filter(e=>e.open).map(id),async restore(open){
  await nextTick();
  for(const element of dialogs()){
   const wanted=Array.isArray(open)&&open.includes(id(element));
   if(!wanted&&element.open)element.close();
   else if(wanted&&!element.open&&element.isConnected&&element.textContent.trim())element.showModal();
  }
 }};
 const registration=navigation.register(view);
 const observer=new Observer(()=>navigation.changed());observer.observe(doc.body,{childList:true,subtree:true,attributes:true,attributeFilter:['open']});
 return {dispose(){observer.disconnect();registration.dispose();}};
}
