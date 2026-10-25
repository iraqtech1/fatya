import assert from 'node:assert/strict';
import {artisan,start,stop,base,probe} from './laravel-harness.mjs';
function client(){let cookie='';return async(path,body,method=body===undefined?'GET':'POST',status=200)=>{const r=await fetch(base+'/api'+path,{method,headers:{Cookie:cookie,Accept:'application/json',...(body===undefined?{}:{'Content-Type':'application/json','X-Fatya-Request':'1'})},body:body===undefined?undefined:JSON.stringify(body)});if(r.headers.get('set-cookie'))cookie=r.headers.get('set-cookie').split(';')[0];const data=await r.json();assert.equal(r.status,status,JSON.stringify(data));return data;};}
try{
  artisan('migrate:fresh','--force');artisan('fatya:seed');await start();
  const admin=client(),customer=client();await admin('/admin/login',{username:'masal',password:'test-password-123456'});
  let s=await admin('/admin/state');
  const save=async(collection,data)=>s=await admin('/admin/'+collection,{revision:s.revision,data},'PUT');
  await save('companies',{name:'Audit company',role:'both',active:true});const company=s.companies[0];
  await save('categories',{name:'تصنيف الفحص',en:'Audit category',icon:'Layers',active:true});const category=s.categories.at(-1);
  const image='data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a/0sAAAAASUVORK5CYII=';
  await save('products',{...s.products[0],id:undefined,name:'بطاقة الفحص',en:'Audit card',category:category.name,manufacturerId:company.id,supplierCompanyId:company.id,values:[5000,10000],prices:[5250,10500],image,denomImages:[image,image],stock:[999,999]});
  const product=s.products.at(-1);assert.deepEqual(product.stock,[0,0],'Stock cannot be edited through product fields');
  await save('customers',{name:'Audit customer',phone:'07812345678',email:'audit@example.test',password:'audit-password-123',active:true});
  const account=s.customers.at(-1);await customer('/auth/login',{phone:account.phone,password:'audit-password-123'});
  await save('suppliers',{name:'Excel audit supplier',url:'',method:'Excel',active:true});
  await save('suppliers',{name:'API audit supplier',url:'https://supplier.example.test/api',method:'API',active:false});
  const slides=[{id:'audit-image-a',name:'First banner',src:image},{id:'audit-image-b',name:'Second banner',src:image}];
  await save('slides',slides);await save('slides',[slides[1],slides[0]]);await save('slides',[slides[1]]);await save('middleSlides',slides);
  assert.equal(probe("echo json_encode(Illuminate\\Support\\Facades\\DB::table('slides')->count());"),1,'Banner removal deletes its stored record');
  await save('settings',{...s.settings,name:'متجر الفحص',supportEmail:'support@example.test',supportPhone:'07712345678',privacyText:'Privacy\nSaved line',aboutText:'About\nSaved line',lowStock:7});
  const imported=await admin('/admin/import',{revision:s.revision,name:'audit.xlsx',rows:[{product_id:product.id,denomination:5000,code:'PRIVATE-AUDIT-1',serial:'0000-audit-1'},{product_id:product.id,denomination:10000,code:'PRIVATE-AUDIT-2',serial:'0000-audit-2'}]});s=imported.state;assert.equal(imported.accepted,2);
  const order=await customer('/orders',{productId:product.id,denom:1,quantity:2,payment:'ZainCash',reference:'persistence-audit'},'POST',201);
  const ticket=await customer('/tickets',{subject:'فحص الحفظ',message:'Verify support persistence'},'POST',201);
  s=await admin('/admin/state');await save('orders',{id:order.id,status:'مكتمل'});await save('tickets',{id:ticket.id,status:'مغلقة',reply:'Persistence checked'});
  assert.equal(order.price,21000);
  const expected=await admin('/admin/state'),publicExpected=await customer('/state');
  await stop();await start();
  assert.deepEqual(await admin('/admin/state'),expected,'Every administrator collection and revision survives process restart');
  assert.deepEqual(await customer('/state'),publicExpected,'Customer identity, catalog, support contacts/text, sliders, orders, tickets and notifications survive restart');
  assert.equal((await admin('/admin/cards/search',{serial:'0000-audit-1'})).card.productId,product.id);
  assert.equal(probe("echo json_encode(Illuminate\\Support\\Facades\\DB::selectOne('SELECT VERSION() AS version')->version); ").includes('MariaDB'),true);
  console.log('Full MariaDB persistence audit passed: products/denomination images, companies/categories, admin-created customers/passwords, suppliers, top/middle banner order/deletion, settings, inventory, orders, replies and notifications.');
}finally{await stop();}
