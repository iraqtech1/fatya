import {connection} from './store.js';
import {connected} from './api.js';
import permissionGroups from '../backend/resources/admin-permissions.json' with {type:'json'};

export {permissionGroups};
export const permissionKeys=permissionGroups.flatMap(g=>Object.keys(g.actions).map(a=>g.id+'.'+a));
export function can(permission){return !connected||connection.access?.permissions?.includes(permission)===true;}
export const canAny=permissions=>permissions.some(can);
export const sectionPermissions={archive:['archive.view'],'application-display':['application_display.view'],notifications:['notifications.view'],prices:['prices.view'],overview:['dashboard.view'],cards:['products.view'],companies:['companies.view'],sources:['companies.view'],categories:['categories.view'],inventory:['inventory.view','exports.view'],'stock-orders':['stock_orders.view'],digital:['digital.view'],orders:['orders.view'],customers:['customers.view'],payments:['payments.view'],suppliers:['suppliers.view'],support:['support.view'],faqs:['faqs.view'],reports:permissionGroups.filter(g=>g.id.startsWith('reports_')).map(g=>g.id+'.view'),'featured-cards':['featured_cards.view'],'web-slider':['web_slides.view'],slider:['slides.view'],'middle-slider':['middle_slides.view'],settings:['settings.view'],account:['my_account.view'],roles:['roles.view'],staff:['staff.view'],'access-audit':['access_audit.view']};
export const canSection=id=>id==='staff-new'?can('staff.create')&&can('staff.assign'):canAny(sectionPermissions[id]||[]);
function permit(el,binding){
  const allowed=Array.isArray(binding.value)?(binding.modifiers?.all?binding.value.every(can):canAny(binding.value)):can(binding.value);
  if(!allowed)el.style.setProperty('display','none','important');else el.style.removeProperty('display');
}
export const permitDirective={mounted:permit,updated:permit};
function readonly(el,binding){
  const allowed=Array.isArray(binding.value)?(binding.modifiers?.all?binding.value.every(can):canAny(binding.value)):can(binding.value);
  for(const field of el.matches('input,select,textarea,button')?[el]:el.querySelectorAll('input,select,textarea,button')){
    if(!allowed){if(!field.hasAttribute('data-access-locked'))field.setAttribute('data-access-was-disabled',String(field.disabled));field.disabled=true;field.setAttribute('data-access-locked','true');}
    else if(field.hasAttribute('data-access-locked')){field.disabled=field.getAttribute('data-access-was-disabled')==='true';field.removeAttribute('data-access-locked');field.removeAttribute('data-access-was-disabled');}
  }
  if(!allowed)el.setAttribute('title','لا تملك صلاحية تعديل هذا الحقل');else el.removeAttribute('title');
}
export const readonlyDirective={mounted:readonly,updated:readonly};
