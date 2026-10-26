import {inRoute,routePath} from './routing.js';

// Browsers show the responsive web store. Installed PWAs and /app keep the app.
export function isWebStore({path=routePath(),standalone=globalThis.matchMedia?.('(display-mode: standalone)').matches||globalThis.navigator?.standalone===true}={}){
  return !standalone&&!inRoute(path,'/app')&&!inRoute(path,'/admin')&&!inRoute(path,'/ar');
}
export const accountPages=new Set(['account','orders','payment']);
