import {api,connected} from './api.js';
import {normalizeBrandSettings} from './branding.js';
import {slides,middleSlides} from './slides.js';
import {reactive,watch} from 'vue/dist/vue.esm-bundler.js';
import {initialCategories} from './categories.js';
import {defaultSettings,readPreviewSettings,savePreviewSettings,subscribePreviewSettings} from './preview-settings.js';
import {readPreviewOrders,savePreviewOrders} from './preview-orders.js';
let previewSettingsStorage;
if(!connected)try{previewSettingsStorage=globalThis.localStorage;}catch{}
export const CATEGORIES=reactive(initialCategories.map(c=>c.name));
import {initialProducts} from './catalog.js';
export const store=reactive({
  categories:initialCategories.map(c=>({...c})),
  companies:[],
  products:initialProducts.map(p=>({...p,active:true,stock:p.values.map(()=>0)})),
  orders:connected?[]:readPreviewOrders(previewSettingsStorage),customers:[],tickets:[],imports:[],suppliers:[],events:[],notifications:[],inventorySummary:[],paymentReceipts:[],
  settings:connected?{...defaultSettings}:readPreviewSettings(previewSettingsStorage),
});
if(!connected&&typeof window!=='undefined')subscribePreviewSettings(previewSettingsStorage,window,next=>Object.assign(store.settings,next));
if(!connected)try{const rows=JSON.parse(previewSettingsStorage?.getItem('fatya-web-slides-v1')||'null');if(Array.isArray(rows))store.settings.webSlides=rows;}catch{}
if(!connected&&typeof window!=='undefined')window.addEventListener('storage',event=>{if(event.key==='fatya-web-slides-v1')try{const rows=JSON.parse(event.newValue);if(Array.isArray(rows))store.settings.webSlides=rows;}catch{}});
export function savePreviewStoreSettings(settings){Object.assign(store.settings,savePreviewSettings(settings,previewSettingsStorage));}
watch(()=>store.categories.map(c=>c.name),names=>CATEGORIES.splice(0,CATEGORIES.length,...names),{flush:'sync'});
export const number=n=>new Intl.NumberFormat('en-US').format(n);
export const isoDate=()=>new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Baghdad',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
export function record(text){const time=new Intl.DateTimeFormat('en-GB',{timeZone:'Asia/Baghdad',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false}).format(new Date());store.events.unshift({id:crypto.randomUUID(),text,date:isoDate()+'T'+time+'+03:00'});store.events.splice(30);}
export function matchesCustomer(record,user){
  if(!record||!user)return false;
  if(record.customerId&&user.id)return record.customerId===user.id;
  if(record.previewId||user.previewId)return !!record.previewId&&record.previewId===user.previewId;
  if(record.phone&&user.phone)return record.phone===user.phone;
  return !!record.email&&!!user.email&&record.email===user.email;
}
export function upsertCustomer(user){
  if(connected||!user)return;
  const customer=store.customers.find(c=>matchesCustomer(c,user));
  if(customer){Object.assign(customer,user);return;}
  if(!customer){store.customers.push({...user,id:crypto.randomUUID(),kind:'فرد',active:true,discount:0,date:isoDate()});record('انضم حساب جديد إلى فاتيا');}
}
export function createOrder(product,denom,quantity,customer,payment='Qi',reference=crypto.randomUUID(),details={}){
  if(connected&&details.demoOnly)throw Error('هذه الخدمة قيد التجهيز');
  if(connected)return api('/orders',{productId:product.id,denom,quantity,payment,reference}).then(async order=>{if(!store.orders.some(o=>o.id===order.id))store.orders.unshift(order);await refreshStore().catch(()=>{});return order;});
  const unit=product.prices[denom];
  const order={id:'FATYA-'+crypto.randomUUID().toUpperCase(),productId:product.id,name:product.name,value:product.values[denom],unit:product.unit||'د.ع',price:unit*quantity,quantity,date:isoDate(),customer:customer?.name||'زائر',phone:customer?.phone||'',email:customer?.email||'',...(customer?.previewId?{previewId:customer.previewId}:{}),kind:'فرد',status:'مكتمل',payment:(payment==='ZainCash'?'ZainCash':'Qi')};
  if(details.telecom)Object.assign(order,{service:details.telecom.service,serviceLabel:details.telecom.label,offerLabel:details.telecom.offerLabel,recipient:details.telecom.recipient,demoOnly:!!details.demoOnly});
  const knownIds=new Set(store.orders.map(o=>o.id));
  const previousOrders=[...store.orders,...readPreviewOrders(previewSettingsStorage).filter(o=>!knownIds.has(o.id))];
  try{savePreviewOrders(previewSettingsStorage,[order,...previousOrders]);}catch{throw Error("تعذّر حفظ الطلب في المتصفح اسمح بالتخزين وأعد المحاولة");}
  if(product.stock[denom]>=quantity)product.stock[denom]-=quantity;
  store.orders.splice(0,store.orders.length,order,...previousOrders);record('طلب جديد: '+product.name);return order;
}
export function createTicket(subject,message,customer){
  if(connected)return api('/tickets',{subject,message}).then(async ticket=>{if(!store.tickets.some(t=>t.id===ticket.id))store.tickets.unshift(ticket);await refreshStore().catch(()=>{});return ticket;});
  store.tickets.unshift({id:'TICKET-'+String(store.tickets.length+1).padStart(4,'0'),subject,message,customer:customer?.name||'زائر',phone:customer?.phone||'',email:customer?.email||'',...(customer?.previewId?{previewId:customer.previewId}:{}),date:isoDate(),status:'مفتوحة',reply:''});record('تذكرة دعم جديدة');
}

export const connection=reactive({admin:false,ready:false,error:'',revision:0,customer:null});
export function setStoreRole(admin){
  if(connection.admin===admin)return;
  connection.admin=admin;connection.ready=false;
  for(const key of ['orders','customers','tickets','imports','suppliers','companies','events','notifications','inventorySummary','paymentReceipts'])store[key].splice(0);
}
let refreshing=null,refreshRole=false;
export function applyState(data){
  if(Number.isInteger(data.revision)&&data.revision<connection.revision)return;
  for(const key of ['categories','companies','products','orders','customers','tickets','imports','suppliers','events','notifications','inventorySummary','paymentReceipts'])if(Array.isArray(data[key]))store[key].splice(0,store[key].length,...data[key]);
  Object.assign(store.settings,normalizeBrandSettings({...data.settings}));
  if(data.slides)slides.splice(0,slides.length,...data.slides);
  if(data.middleSlides)middleSlides.splice(0,middleSlides.length,...data.middleSlides);
  if(!connection.admin)connection.customer=data.viewer||null;
  connection.revision=data.revision;connection.ready=true;connection.error='';
}
export async function refreshStore(){
  if(!connected)return;
  if(refreshing){const sameRole=refreshRole===connection.admin;await refreshing;if(sameRole)return;return refreshStore();}
  refreshRole=connection.admin;
  refreshing=(async()=>{const role=refreshRole;try{const data=await api(role?'/admin/state':'/state');if(role===connection.admin)applyState(data);}catch(e){if(role===connection.admin){if(role&&e.status===401)setStoreRole(false);connection.error=e.message;}throw e;}finally{refreshing=null;}})();
  return refreshing;
}
export async function saveAdmin(collection,data,revision=connection.revision){
  try{applyState(await api('/admin/'+collection,{revision,data},'PUT'));await refreshStore().catch(()=>{});}
  catch(e){await refreshStore().catch(()=>{});throw e;}
}
export async function readNotifications(ids){
  const result=await api('/notifications/read',{ids});
  if(result.revision>=connection.revision){store.notifications.splice(0,store.notifications.length,...result.notifications);connection.revision=result.revision;}
}
export async function importStock(rows,name){
  try{const result=await api('/admin/import',{revision:connection.revision,rows,name});applyState(result.state);await refreshStore().catch(()=>{});return result;}
  catch(e){await refreshStore().catch(()=>{});throw e;}
}
