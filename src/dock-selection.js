export function selectedDockItem(items,page){
  const section=page==='payment'?'cards':page;
  return items.find(item=>item.id===section)||items[0];
}
