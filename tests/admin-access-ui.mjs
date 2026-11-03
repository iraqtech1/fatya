import {matrixRows,matrixColumns,matrixSelection} from '../src/permission-matrix.js';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createRequire} from 'node:module';
const require=createRequire(import.meta.url),Vue=require('vue'),{renderToString}=require('@vue/server-renderer');
const groups=JSON.parse(readFileSync(new URL('../backend/resources/admin-permissions.json',import.meta.url),'utf8'));
const icons=require('lucide-vue-next');
const keys=groups.flatMap(g=>Object.keys(g.actions).map(a=>g.id+'.'+a));
assert.equal(new Set(keys).size,keys.length);
const source=readFileSync(new URL('../src/AdminAccessManager.js',import.meta.url),'utf8').replace(/^import .*;\r?\n/gm,'').replace('export default','return');
function component(owner=true,permissions=keys){return Function('matrixRows','matrixColumns','matrixSelection','onBeforeUnmount','nextTick','ShieldCheck','Plus','Users','Pencil','Trash2','X','Eye','EyeOff','computed','onMounted','ref','watch','api','connected','connection','can','permissionGroups','permissionKeys',source)(matrixRows,matrixColumns,matrixSelection,()=>{},Vue.nextTick,icons.ShieldCheck,icons.Plus,icons.Users,icons.Pencil,icons.Trash2,icons.X,icons.Eye,icons.EyeOff,Vue.computed,()=>{},Vue.ref,()=>{},()=>{throw Error('Rendering must not send requests');},false,{access:{owner,id:owner?'owner':'employee'}},p=>permissions.includes(p),groups,keys);}
async function render(c,mode,context){const warnings=[],app=Vue.createSSRApp(context?{...c,setup:()=>context}:c,{mode});app.config.warnHandler=m=>warnings.push(m);const html=await renderToString(app);assert.deepEqual(warnings,[],mode+' must render without undefined template bindings');return html;}
for(const mode of ['roles','staff','staff-new','access-audit'])await render(component(),mode);
const c=component(),roleContext=c.setup({mode:'roles'});
roleContext.roles.value=[{id:'role-1',name:'دعم',description:'',unrestricted:false,permissions:['support.view'],version:1}];
roleContext.editRole(roleContext.roles.value[0]);
roleContext.draft.value.name='Changed draft';
assert.equal(roleContext.roles.value[0].name,'دعم','Editing must clone reactive role data');
roleContext.toggleDetails('support');
const roleHtml=await render(c,'roles',roleContext);
assert.ok(roleHtml.includes('support.view')&&roleHtml.includes('الصلاحيات'));
const staffContext=c.setup({mode:'staff'});staffContext.roleOptions.value=[{id:'role-1',name:'دعم',canAssign:true}];staffContext.editStaff();
assert.equal(staffContext.draft.value.roleId,'role-1');
assert.ok((await render(c,'staff',staffContext)).includes('autocomplete="new-password"'));
const limited=component(false,['roles.view','roles.create','support.view']),limitedContext=limited.setup({mode:'roles'});limitedContext.editRole();limitedContext.selectAll(true);
assert.deepEqual(limitedContext.draft.value.permissions,['roles.view','roles.create','support.view'].sort((a,b)=>keys.indexOf(a)-keys.indexOf(b)));
assert.equal((await render(limited,'roles',limitedContext)).includes('صلاحية مطلقة لجميع عمليات النظام'),false);
const newContext=c.setup({mode:'staff-new'});newContext.roleOptions.value=staffContext.roleOptions.value;newContext.editStaff();const newHtml=await render(c,'staff-new',newContext);for(const label of ['البريد الإلكتروني','معلومات عن المدير','صورة المدير','اسم مجموعة الصلاحية'])assert.ok(newHtml.includes(label),label);
assert.ok(roleHtml.includes('role="dialog"')&&roleHtml.includes('تفاصيل صلاحيات'));
roleContext.roles.value[0].staffCount=3;assert.ok((await render(c,'roles',roleContext)).includes('إجمالي 3 مستخدمين'));
console.log(`Access manager renders all modes and editors, clones roles, and limits delegated grants (${keys.length} permissions).`);

const accountSource=readFileSync(new URL('../src/AdminAccount.js',import.meta.url),'utf8').replace(/^import .*;\r?\n/gm,'').replace('export default','return');
const account=Function('ref','onMounted','Eye','EyeOff','Users','api','connected','can','refreshStore','readStaffImage',accountSource)(Vue.ref,()=>{},icons.Eye,icons.EyeOff,icons.Users,()=>{throw Error('No API during SSR');},true,p=>keys.includes(p),()=>{},()=>{});
const context=account.setup();context.draft.value={name:'Test',username:'test',email:'test@example.test',about:'Profile',image:'',version:1,password:'',currentPassword:''};
const accountHtml=await render(account,'account',context);assert.ok(accountHtml.includes('حفظ التغييرات')&&accountHtml.includes('كلمة المرور الحالية'));
console.log('My account renders profile and current-password confirmation without undefined bindings.');

assert.deepEqual(matrixRows.flatMap(r=>r.keys).sort(),[...keys].sort(),'Every granular permission belongs to exactly one Fatya section');
const inventory=matrixRows.find(r=>r.id==='inventory');assert.ok(!inventory.columns.view.includes('inventory.codes'),'Showing inventory cannot silently grant secret card codes');
const row=matrixRows.find(r=>r.id==='customers');roleContext.selectAll(false);roleContext.toggleCell(row.columns.update,true);assert.ok(roleContext.draft.value.permissions.includes('customers.update'));assert.ok(!roleContext.draft.value.permissions.includes('customers.password'));
assert.equal(roleContext.cellState(row.columns.update).checked,true);roleContext.toggleCell(['customers.update'],false);assert.equal(roleContext.cellState(row.columns.update).partial,true);
limitedContext.toggleCell(row.columns.update,true);assert.ok(!limitedContext.draft.value.permissions.includes('customers.update'),'Matrix selection cannot grant unowned permissions');
console.log('Compact matrix maps every permission once, supports partial selections, and keeps secret access separate.');
