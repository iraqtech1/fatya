export const FEATURED_KEY='fatya-featured-cards-v1';
export const featuredDefaults={visible:true,webVisible:true,interval:3200,label:'رصيد • ألعاب • بطاقات عالمية',title:'بطاقات الرصيد',accent:'والألعاب',backgroundStart:'#034037',backgroundEnd:'#09845f',backgroundImage:''};
export function featuredConfig(settings,products=[],categories){
  const saved=settings?.featuredCards;
  const ordered=products.map((p,i)=>({p,order:p.displayOrder??(i+1)*10})).sort((a,b)=>a.order-b.order).map(row=>row.p);
  return {...featuredDefaults,productIds:ordered.filter(p=>p.active&&(!categories||categories.some(c=>c.active&&c.name===p.category))).slice(0,6).map(p=>p.id),...saved};
}
export function featuredProducts(config,products,categories){
  return config.productIds.map(id=>products.find(p=>String(p.id)===String(id))).filter(p=>p&&p.active&&(!categories||categories.some(c=>c.active&&c.name===p.category)));
}
export function featuredBackground(config){
  const gradient=`linear-gradient(120deg,${config.backgroundStart},${config.backgroundEnd})`;
  const background=config.backgroundImage?`linear-gradient(#0006,#0006),url(${JSON.stringify(config.backgroundImage)}) center/cover no-repeat`:gradient;
  return {background:background+' !important'};
}
