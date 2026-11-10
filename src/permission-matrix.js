import groups from '../backend/resources/admin-permissions.json' with {type:'json'};

export const matrixColumns=[{id:'view',label:'إظهار'},{id:'create',label:'إنشاء'},{id:'update',label:'تعديل'},{id:'delete',label:'حذف'}];
const sections=[
  ['overview','لوحة التحكم',['dashboard','events']],
  ['cards','البطاقات',['products','companies']],
  ['categories','التصنيفات',['categories']],
  ['inventory','المخزون والاستيراد',['inventory','exports']],
  ['stock-orders','رفع الطلبيات',['stock_orders']],
  ['digital','TOPUP والرابعة',['digital']],
  ['orders','الطلبات والمبيعات',['orders']],
  ['customers','إدارة العملاء',['customers']],
  ['payments','المدفوعات Qi',['payments']],
  ['suppliers','المورّدون API',['suppliers']],
  ['faqs','الإجابات السريعة',['faqs']],
  ['support','الدعم والتذاكر',['support']],
  ['reports','التقارير',groups.filter(g=>g.id.startsWith('reports_')||g.id==='finance').map(g=>g.id)],
  ['featured-cards','الكارتات المميزة',['featured_cards']],
  ['web-slider','سلايدر المتجر',['web_slides']],
  ['slider','سلايدر التطبيق',['slides']],
  ['middle-slider','السلايدر الوسطي',['middle_slides']],
  ['roles','الصلاحيات',['roles']],
  ['staff','المدراء',['staff']],
  ['access-audit','سجل الموظفين والصلاحيات',['access_audit']],
  ['account','حسابي',['my_account']],
  ['settings','الإعدادات',['settings']],
];
const extraViews=new Set(['dashboard.sales','dashboard.orders','dashboard.stock','dashboard.customers','dashboard.support','inventory.history','digital.connections_view','digital.publications_view','digital.offers_view']);
const extraCreates=new Set(['inventory.import','stock_orders.read_file','stock_orders.preview','stock_orders.submit']);
const edits=new Set(['update','images','denominations','prices','visibility','companies','import_fields','status','reply','reorder','identity','contacts','stock','legal','publications_update','offers']);
export const matrixRows=sections.map(([id,name,ids])=>{
  const details=groups.filter(g=>ids.includes(g.id)).map(g=>({...g,entries:Object.entries(g.actions)}));
  const keys=details.flatMap(g=>g.entries.map(([action])=>g.id+'.'+action));
  const columns=Object.fromEntries(matrixColumns.map(c=>[c.id,keys.filter(key=>{
    const action=key.split('.')[1];
    if(c.id==='view')return action==='view'||extraViews.has(key);
    if(c.id==='create')return action==='create'||extraCreates.has(key);
    if(c.id==='delete')return action==='delete';
    return !key.startsWith('dashboard.')&&edits.has(action);
  })]));
  return {id,name,details,keys,columns};
});
export const matrixSelection=(keys,permissions,can)=>{const owned=keys.filter(can),count=owned.filter(p=>permissions.includes(p)).length;return {checked:owned.length>0&&count===owned.length,partial:count>0&&count<owned.length,disabled:owned.length===0};};
