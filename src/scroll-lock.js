export const popupSelector='dialog[open],[role="dialog"][aria-modal="true"],details.searchable-filter[open],.admin-overlay,.ms-menu[aria-expanded="true"],.ms-dropdown-panel,.ms-filter-menu,[data-scroll-lock="true"]';
export function installScrollLock({win=globalThis.window,doc=globalThis.document,Observer=globalThis.MutationObserver}={}){
 if(!win||!doc?.body||!doc.documentElement||!Observer)return{sync(){},dispose(){}};
 const root=doc.documentElement,body=doc.body,properties=['position','top','left','right','width'];
 let saved=null,routeChanged=false;
 function unlock(){if(!saved)return;const previous=saved;saved=null;for(const [key,value,priority] of previous.styles){if(value)body.style.setProperty(key,value,priority);else body.style.removeProperty(key);}root.removeAttribute('data-fatya-scroll-locked');if(!routeChanged)win.scrollTo({left:previous.x,top:previous.y,behavior:'instant'});routeChanged=false;}
 function sync(){const visible=[...doc.querySelectorAll(popupSelector)].some(e=>e.getClientRects().length>0);
  if(visible&&!saved){saved={x:win.scrollX,y:win.scrollY,styles:properties.map(key=>[key,body.style.getPropertyValue(key),body.style.getPropertyPriority(key)])};routeChanged=false;root.setAttribute('data-fatya-scroll-locked','');body.style.setProperty('position','fixed');body.style.setProperty('top',-saved.y+'px');body.style.setProperty('left',-saved.x+'px');body.style.setProperty('right','0');body.style.setProperty('width','100%');}
  else if(!visible)unlock();
 }
 function route(){if(saved)routeChanged=true;}
 function blockBackground(event){if(saved&&event.cancelable&&!event.ctrlKey&&(!event.touches||event.touches.length===1)&&!event.target?.closest?.('dialog[open],[role="dialog"][aria-modal="true"],.searchable-filter-panel,.admin-sidebar.is-open,.ms-nav-inner nav.ms-visible,.ms-dropdown-panel,.ms-filter-menu,[data-scroll-lock="true"]'))event.preventDefault();}
 const observer=new Observer(sync);observer.observe(doc.body,{subtree:true,childList:true,attributes:true,attributeFilter:['open','class','aria-modal','aria-expanded','aria-hidden','hidden','style','data-scroll-lock']});
 win.addEventListener('wheel',blockBackground,{passive:false,capture:true});win.addEventListener('touchmove',blockBackground,{passive:false,capture:true});win.addEventListener('resize',sync);win.addEventListener('fatya:routechange',route);sync();
 return{sync,dispose(){observer.disconnect();win.removeEventListener('wheel',blockBackground,true);win.removeEventListener('touchmove',blockBackground,true);win.removeEventListener('resize',sync);win.removeEventListener('fatya:routechange',route);unlock();}};
}
