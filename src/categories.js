export const CATEGORY_ICON_LABELS={Signal:'اتصالات',Wifi:'إنترنت',Gamepad2:'ألعاب',ShoppingBag:'متجر',Smartphone:'هاتف',Layers:'مجموعة',CreditCard:'بطاقة',Headphones:'دعم'};
export const CATEGORY_ICONS=['Signal','Wifi','Gamepad2','ShoppingBag','Smartphone','Layers','CreditCard','Headphones'];
export const initialCategories=[
 {id:'mobile',name:'اتصالات',en:'Mobile top-ups',icon:'Signal',active:true},
 {id:'internet',name:'إنترنت',en:'Internet',icon:'Wifi',active:true},
 {id:'games',name:'ألعاب',en:'Games',icon:'Gamepad2',active:true},
 {id:'global',name:'متاجر عالمية',en:'Global stores',icon:'ShoppingBag',active:true},
 {id:'apps',name:'تطبيقات',en:'Apps',icon:'Smartphone',active:true},
];
export function categoriesForProducts(products){const categories=initialCategories.map(c=>({...c}));for(const p of products)if(!categories.some(c=>c.name===p.category))categories.push({id:'legacy-'+categories.length,name:p.category,en:p.category,icon:'Layers',active:true});return categories;}
export function upsertCategory(categories,products,d,makeId=()=>crypto.randomUUID()){
 const old=categories.find(c=>c.id===d.id);
 if(d.id&&!old)throw Error('التصنيف غير موجود');
 const name=typeof d.name==='string'?d.name.trim():'',en=typeof d.en==='string'?d.en.trim():'';
 if(!name||name.length>40||name==='الكل'||!en||en.length>60||!CATEGORY_ICONS.includes(d.icon)||typeof d.active!=='boolean')throw Error('اكتب اسم التصنيف والاسم الإنجليزي واختر أيقونة صحيحة');
 if(categories.some(c=>c.id!==d.id&&c.name===name))throw Error('اسم التصنيف موجود بالفعل');
 const item={id:old?.id||makeId(),name,en,icon:d.icon,active:d.active};
 if(old){for(const p of products)if(p.category===old.name)p.category=name;Object.assign(old,item);}else categories.push(item);
 return item;
}
