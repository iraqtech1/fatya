export const pageCount=(total,size)=>Math.max(1,Math.ceil(total/size));
export const clampPage=(page,total,size)=>Math.min(Math.max(1,page),pageCount(total,size));
export function pageItems(items,page,size){const start=(clampPage(page,items.length,size)-1)*size;return items.slice(start,start+size);}
export function pageNumbers(page,total){const start=Math.max(1,Math.min(page-2,total-4));return Array.from({length:Math.min(5,total)},(_,i)=>start+i);}
