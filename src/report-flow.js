import {ref,computed} from 'vue/dist/vue.esm-bundler.js';

export const emptyReportFilters=()=>({from:'',to:'',productId:'',manufacturerId:'',supplier:'',status:'',actor:'',channel:'',batchId:'',serial:'',denomination:''});
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
export function useReportFlow({can,request,download}){
 const path=ref([]),filters=ref(emptyReportFilters()),appliedFilters=ref(emptyReportFilters()),result=ref(null),busy=ref(false),error=ref(''),message=ref(''),tablesOpen=ref(false);
 const cards=computed(()=>tree.flatMap(node=>{
  if(!node.children)return can('reports_'+node.id+'.view')?[node]:[];
  const children=node.children.filter(c=>can('reports_'+c.id+'.view'));
  return children.length?[{...node,children}]:[];
 }));
 const current=computed(()=>path.value.at(-1)||null),type=computed(()=>current.value&&!current.value.children?current.value.id:''),visibleCards=computed(()=>current.value?.children||cards.value);
 const customized=computed(()=>Object.values(appliedFilters.value).some(v=>v!==''));
 const dirty=computed(()=>JSON.stringify(filters.value)!==JSON.stringify(appliedFilters.value));
 function reset(){filters.value=emptyReportFilters();appliedFilters.value=emptyReportFilters();result.value=null;error.value='';message.value='';tablesOpen.value=false;}
 async function open(card){
  if(busy.value||!visibleCards.value.some(c=>c.id===card.id))return;
  path.value=[...path.value,card];reset();
  if(type.value)await load();
 }
 function back(depth=0){if(busy.value)return;path.value=path.value.slice(0,depth);reset();}
 async function load(page=1,scope=appliedFilters.value){
  if(busy.value||!type.value)return false;
  busy.value=true;error.value='';message.value='';
  const snapshot={...scope};
  try{const data=await request({type:type.value,filters:snapshot,page,all:false});result.value=data;appliedFilters.value=snapshot;return true;}
  catch(e){error.value=e.message;return false;}finally{busy.value=false;}
 }
 async function apply(){if(await load(1,filters.value))tablesOpen.value=true;}
 async function clear(){if(busy.value)return;filters.value=emptyReportFilters();await apply();}
 async function exportReport(scope='filtered'){
  if(busy.value||!type.value||!can('reports_'+type.value+'.export'))return;
  busy.value=true;error.value='';message.value='';
  try{
   const data=await request({type:type.value,filters:scope==='all'?emptyReportFilters():{...appliedFilters.value},page:1,all:true});
   if(!data.rows.length){message.value='لا توجد نتائج للتصدير.';return;}
   await download(type.value,data,scope);message.value='تم تجهيز ملف Excel';
  }catch(e){error.value=e.message;}finally{busy.value=false;}
 }
 return {path,filters,appliedFilters,result,busy,error,message,tablesOpen,cards,current,type,visibleCards,customized,dirty,open,back,load,apply,clear,exportReport};
}
