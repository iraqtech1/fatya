export function webSlideRows(settings,products){
  if(Array.isArray(settings?.webSlides))return settings.webSlides;
  return ['steam','korek','zain'].map(name=>products.find(p=>p.active!==false&&p.en?.toLowerCase()===name)).filter(Boolean).map(p=>({id:'web-default-'+p.id,name:p.name,src:p.image,productId:p.id}));
}
export function webSlideCards(settings,products){
  return webSlideRows(settings,products).map(slide=>{const product=products.find(p=>String(p.id)===String(slide.productId)&&p.active!==false);return product&&slide.src?{...product,id:slide.id,productId:product.id,image:slide.src}:null;}).filter(Boolean);
}
