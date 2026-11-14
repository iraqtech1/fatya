export function notificationId(url=globalThis.location?.href){try{const id=new URL(url).searchParams.get('notification');return /^[0-9a-f]{8}-[0-9a-f-]{27}$/i.test(id||'')?id:'';}catch{return '';}}
export function notificationTarget(path){return typeof path==='string'&&/^\/(?:app|cards|orders|account|support|)(?:\?order=[A-Za-z0-9_-]{1,100})?$/.test(path)?path:'';}
export async function closeReadNotifications(ids){
 try{const registrations=await navigator.serviceWorker?.getRegistrations();for(const registration of registrations||[]){for(const notification of await registration.getNotifications()){const id=notification.data?.notification_id||notification.data?.data?.notification_id;if(ids.includes(id)||ids.includes(notification.tag))notification.close();}}}catch{}
}
