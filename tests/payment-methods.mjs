import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createRequire} from 'node:module';
import * as Vue from 'vue';
const require=createRequire(import.meta.url),vueRequire=createRequire(require.resolve('vue/package.json'));
const {compile}=vueRequire('@vue/compiler-dom');
const source=readFileSync('src/PaymentMethods.js','utf8');
const template=source.slice(source.indexOf('template:`')+10,source.lastIndexOf('`'));
const render=Function('Vue',compile(template,{mode:'function',isCustomElement:tag=>tag==='Check'}).code)(Vue);
function buttons(node){return Array.isArray(node)?node.flatMap(buttons):node&&typeof node==='object'?[...(node.type==='button'?[node]:[]),...buttons(node.children)]:[];}
let chosen='Qi';
for(const target of ['ZainCash','Qi']){
  const context={t:x=>x,modelValue:chosen,$emit:(name,value)=>{assert.equal(name,'update:modelValue');chosen=value;}};
  const initial=buttons(render(context,[]));
  assert.equal(initial.filter(b=>b.props['aria-pressed']).length,1);
  initial.find(b=>b.props['aria-label'].endsWith(target)).props.onClick();
  assert.equal(chosen,target,'Clicking the actual rendered button selects the requested provider');
  context.modelValue=chosen;
  const updated=buttons(render(context,[]));
  assert.equal(updated.filter(b=>b.props['aria-pressed']).length,1);
  assert.equal(updated.find(b=>b.props['aria-pressed']).props['aria-label'],'الدفع بواسطة '+target);
  assert.ok(updated.find(b=>b.props['aria-pressed']).props.class.includes('selected'));
  assert.ok(updated.every(b=>b.props.type==='button'));
}
console.log('Payment provider switching and exclusive visible/accessible selection checks passed.');
