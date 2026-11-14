import {ref,computed} from 'vue/dist/vue.esm-bundler.js';

export const emptyReportFilters=()=>({from:'',to:'',productId:'',manufacturerId:'',supplier:'',status:'',actor:'',channel:'',batchId:'',serial:'',denomination:'',provider:'',serviceType:'',orderId:'',customerId:'',query:''});
const tree=[
 {id:'stock',name:'المخزون والتوريد',description:'الأرصدة وحركة البطاقات والطلبيات',children:[
  {id:'inventory',name:'المخزون',description:'الكميات والحالات والتكلفة المتاحة'},
  {id:'movements',name:'حركة المخزون خلال الفترة',description:'الرصيد الافتتاحي والوارد والصادر والختامي'},
  {id:'procurement',name:'الطلبيات والتوريد',description:'الملفات المرفوعة والكميات المقبولة والتكاليف'}]},
 {id:'commerce',name:'المبيعات والدفع',description:'المبيعات والتكلفة والتنفيذ والتسويات',children:[
  {id:'sales',name:'المبيعات والتكلفة',description:'المبالغ والتكاليف والأرباح'},
  {id:'digital',name:'تنفيذ API',description:'حالات تنفيذ الطلبات ومراجع المزوّد'},
  {id:'payments',name:'الدفع والتسويات',description:'إيصالات الدفع والمبالغ والحالات'}]},
 {id:'exports',name:'النسخ والسحب',description:'عمليات تصدير البطاقات وسحب المخزون'},
 {id:'audit',name:'سجل التدقيق',description:'إجراءات المستخدمين ومراجع العمليات'}
];
export function useReportFlow({can,request,download,defaultFilters={}}){
 let generation=0;
 const initial=()=>({...emptyReportFilters(),...defaultFilters});
 const path=ref([]),filters=ref(initial()),appliedFilters=ref(initial()),result=ref(null),busy=ref(false),error=ref(''),message=ref(''),tablesOpen=ref(false);
 const cards=computed(()=>tree.flatMap(node=>{
  if(!node.children)return can('reports_'+node.id+'.view')?[node]:[];
  const children=node.children.filter(c=>can('reports_'+c.id+'.view'));
  return children.length?[{...node,children}]:[];
 }));
 const current=computed(()=>path.value.at(-1)||null),type=computed(()=>current.value&&!current.value.children?current.value.id:''),visibleCards=computed(()=>current.value?.children||cards.value);
 const customized=computed(()=>Object.values(appliedFilters.value).some(v=>v!==''));
 const dirty=computed(()=>JSON.stringify(filters.value)!==JSON.stringify(appliedFilters.value));
 function reset(){generation++;busy.value=false;filters.value=initial();appliedFilters.value=initial();result.value=null;error.value='';message.value='';tablesOpen.value=false;}
 async function open(card){
  if(busy.value||!visibleCards.value.some(c=>c.id===card.id))return;
  path.value=[...path.value,card];reset();
  if(type.value)await load();
 }
 function back(depth=Math.max(0,path.value.length-1)){if(busy.value)return;path.value=path.value.slice(0,depth);reset();}
 async function restorePath(ids){
  const resolved=[];let available=cards.value;
  for(const id of ids.slice(0,4)){const node=available.find(n=>n.id===id);if(!node)break;resolved.push(node);available=node.children||[];}
  if(JSON.stringify(resolved.map(n=>n.id))===JSON.stringify(path.value.map(n=>n.id)))return;
  path.value=resolved;reset();if(type.value)await load();
 }
 async function load(page=1,scope=appliedFilters.value){
  if(busy.value||!type.value)return false;
  busy.value=true;error.value='';message.value='';
  const snapshot={...scope},token=++generation;
  try{const data=await request({type:type.value,filters:snapshot,page,all:false});if(token!==generation)return false;result.value=data;appliedFilters.value=snapshot;return true;}
  catch(e){if(token===generation)error.value=e.message;return false;}finally{if(token===generation)busy.value=false;}
 }
 async function apply(){if(await load(1,filters.value))tablesOpen.value=true;}
 async function clear(){if(busy.value)return;filters.value=emptyReportFilters();await apply();}
 async function exportReport(scope='filtered'){
  if(busy.value||!type.value||!can('reports_'+type.value+'.export'))return;
  busy.value=true;error.value='';message.value='';const token=++generation;
  try{
   const data=await request({type:type.value,filters:scope==='all'?emptyReportFilters():{...appliedFilters.value},page:1,all:true});
   if(token!==generation)return;
   if(!data.rows.length){message.value='لا توجد نتائج للتصدير.';return;}
   await download(type.value,data,scope);message.value='تم تجهيز ملف Excel';
  }catch(e){if(token===generation)error.value=e.message;}finally{if(token===generation)busy.value=false;}
 }
 return {path,filters,appliedFilters,result,busy,error,message,tablesOpen,cards,current,type,visibleCards,customized,dirty,open,back,restorePath,load,apply,clear,exportReport};
}
