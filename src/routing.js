// Static GitHub Pages previews retain hash history; the hosted app uses paths.
export const hashRouting=import.meta.env?.VITE_DATA_MODE==='preview';
export function routePath(current=globalThis.location,hashMode=hashRouting){
  if(hashMode||!current?.pathname)return (current?.hash||'#/').replace(/^#/, '').split(/[?#]/)[0]||'/';
  return current.pathname;
}
export function routeHref(path,hashMode=hashRouting){return hashMode?'#'+path:path;}
export function inRoute(path,prefix){return path===prefix||path.startsWith(prefix+'/');}
let navigateWithRouter;
export function setRouteNavigator(navigate){navigateWithRouter=navigate;}
export function navigateRoute(path){
  if(navigateWithRouter)return navigateWithRouter(path);
  if(hashRouting){location.hash=path;return;}
  history.pushState(null,'',path);
  window.dispatchEvent(new PopStateEvent('popstate',{state:history.state}));
}
export function legacyDestination(current){
  if(!/^#\/(?:(?:admin|ar)(?:[/?#]|$)|[?#]|$)/.test(current.hash||''))return null;
  const destination=new URL(current.hash.slice(1),'https://route.invalid');
  for(const [key,value] of new URLSearchParams(current.search||''))if(!destination.searchParams.has(key))destination.searchParams.append(key,value);
  return destination.pathname+destination.search+destination.hash;
}
