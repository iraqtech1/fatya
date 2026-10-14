import assert from 'node:assert/strict';
import {SETTINGS_KEY,defaultSettings,readPreviewSettings,savePreviewSettings,subscribePreviewSettings} from '../src/preview-settings.js';

const data=new Map();
const storage={getItem:key=>data.get(key)??null,setItem:(key,value)=>data.set(key,value)};
assert.deepEqual(readPreviewSettings(storage),defaultSettings);
const saved=savePreviewSettings({...defaultSettings,supportPhone:'07712345678',supportEmail:'support@example.test',name:'Test store'},storage);
assert.equal(saved.supportPhone,'07712345678');
assert.deepEqual(readPreviewSettings(storage),saved,'New page loads recover saved support contacts');

const events=new Map();
const target={addEventListener:(type,handler)=>events.set(type,handler),removeEventListener:type=>events.delete(type)};
let otherTab=readPreviewSettings(storage);
const unsubscribe=subscribePreviewSettings(storage,target,next=>otherTab=next);
savePreviewSettings({...saved,supportPhone:'07787654321'},storage);
events.get('storage')({key:SETTINGS_KEY,storageArea:storage});
assert.equal(otherTab.supportPhone,'07787654321','An open storefront receives dashboard contact updates');
savePreviewSettings({...saved,supportPhone:''},storage);
events.get('storage')({key:SETTINGS_KEY,storageArea:storage});
assert.equal(otherTab.supportPhone,'','Clearing the contact propagates to open pages');
savePreviewSettings(saved,storage);
events.get('storage')({key:'other-key'});
assert.equal(otherTab.supportPhone,'','Unrelated storage changes are ignored');
data.clear();events.get('storage')({key:null,storageArea:storage});
assert.deepEqual(otherTab,defaultSettings,'Clearing browser storage restores empty contact defaults');
unsubscribe();assert.equal(events.size,0);

data.set(SETTINGS_KEY,'invalid JSON');assert.deepEqual(readPreviewSettings(storage),defaultSettings);
data.set(SETTINGS_KEY,JSON.stringify({supportPhone:42,lowStock:-1,name:''}));assert.deepEqual(readPreviewSettings(storage),defaultSettings);
const before=storage.getItem(SETTINGS_KEY);
assert.throws(()=>savePreviewSettings(saved,{setItem(){throw Error('quota');}}),/تعذّر حفظ/);
assert.equal(storage.getItem(SETTINGS_KEY),before,'Failed persistence does not change saved settings');
assert.deepEqual(readPreviewSettings({getItem(){throw Error('blocked');}}),defaultSettings);
console.log('Preview support contacts persist across loads and synchronize across tabs, with explicit storage failures.');

const content={...defaultSettings,privacyText:'خصوصية مخصصة\nسطر ثانٍ <script>test</script>',aboutText:'حول متجرنا\nبطاقات رقمية'};
savePreviewSettings(content,storage);assert.equal(readPreviewSettings(storage).privacyText,content.privacyText);assert.equal(readPreviewSettings(storage).aboutText,content.aboutText);
const textEvents=new Map(),targetTexts={addEventListener:(name,fn)=>textEvents.set(name,fn),removeEventListener:name=>textEvents.delete(name)};let synced;
const stopTexts=subscribePreviewSettings(storage,targetTexts,next=>synced=next);textEvents.get('storage')({key:SETTINGS_KEY,storageArea:storage});assert.equal(synced.aboutText,content.aboutText);stopTexts();
const {readFileSync}=await import('node:fs');const {createSSRApp}=await import('vue');const {renderToString}=await import('@vue/server-renderer');const accountSource=readFileSync('src/AccountPage.js','utf8');
for(const [view,key]of [['privacy','privacyText'],['about','aboutText']]){
 const template=accountSource.slice(accountSource.indexOf('<article v-if="view===\''+view+'\'"'));const article=template.slice(0,template.indexOf('</article>')+10);
 const render=settings=>renderToString(createSSRApp({template:article,setup:()=>({view,store:{settings},t:phrase=>'translated:'+phrase})}));
 const html=await render(content);assert.ok(html.includes('account-custom-text'));assert.ok(html.includes(key==='privacyText'?'&lt;script&gt;test&lt;/script&gt;':'حول متجرنا'));assert.ok(!html.includes('translated:'+content[key]),'Custom text is not rewritten by UI translation');
 const fallback=await render(defaultSettings);assert.ok(!fallback.includes('account-custom-text'),'Empty text preserves existing fallback content');assert.ok(fallback.includes('translated:'));
}
console.log('Custom privacy and about text persists, syncs and renders as escaped text with default fallback.');
