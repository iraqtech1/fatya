import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createRequire} from 'node:module';
import * as Vue from 'vue';

const require=createRequire(import.meta.url),vueRequire=createRequire(require.resolve('vue/package.json'));
const {compile}=vueRequire('@vue/compiler-dom');
const source=readFileSync(new URL('../src/Dashboard.js',import.meta.url),'utf8');
const dialogTag=source.match(/<dialog\b[^>]*class="admin-dialog"[^>]*>/)?.[0];
assert.ok(dialogTag,'Dashboard management dialog exists');
const render=Function('Vue',compile(dialogTag+'</dialog>',{mode:'function'}).code)(Vue);
const dialog={},fileInput={},draft={name:'New card',values:[5000],prices:[5250],denomImages:['existing-image']};
const expectedDraft=structuredClone(draft);
let closes=0;
const context={dialog,modal:'product',draft,close(){closes++;context.modal=null;}};
const vnode=render(context,[]);

// Native file inputs bubble cancel when their picker is dismissed or the
// same file is selected again. Run the handler compiled from the real template.
for(const reason of ['picker dismissed','same file selected again']){
  let prevented=false;
  vnode.props.onCancel({target:fileInput,currentTarget:dialog,preventDefault(){prevented=true;}});
  assert.equal(context.modal,'product',reason+': card editor stays open');
  assert.equal(closes,0,reason+': no close action');
  assert.equal(prevented,false,'Nested file cancel is ignored');
  assert.deepEqual(context.draft,expectedDraft,'Draft and existing image are preserved');
}

let prevented=false;
vnode.props.onCancel({target:dialog,currentTarget:dialog,preventDefault(){prevented=true;}});
assert.equal(prevented,true,'Native dialog cancellation is handled explicitly');
assert.equal(closes,1,'Escape on the dialog still closes it once');
assert.equal(context.modal,null);
console.log('Dashboard file-picker cancellation preserves the editor and draft; dialog Escape still closes.');
