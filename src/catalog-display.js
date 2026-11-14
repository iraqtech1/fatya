export const defaultCatalogLayout=()=>({mode:'grid',columns:2,flatCompanies:false});
export function catalogLayout(settings,category,categories=[]){const id=categories.find(row=>row.name===category)?.id;return {...defaultCatalogLayout(),...settings?.default,...settings?.categories?.[id]};}
export function flatCompanies(companies){return companies.flatMap(company=>[{...company,children:[]},...flatCompanies(company.children||[])]).filter(company=>company.products.length);}
