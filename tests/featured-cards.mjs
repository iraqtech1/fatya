import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createRequire} from 'node:module';
import {FEATURED_KEY,featuredConfig,featuredProducts,featuredBackground} from '../src/featured-cards.js';
const require=createRequire(import.meta.url),Vue=require('vue');
const products=[{id:1,name:'First',active:true,category:'A'},{id:2,name:'Second',active:true,category:'A'},{id:3,name:'Hidden',active:false,category:'A'},{id:4,name:'Disabled category',active:true,category:'B'}],categories=[{name:'A',active:true},{name:'B',active:false}];
assert.deepEqual(featuredConfig({},products,categories).productIds,[1,2]);
const config={...featuredConfig({},products,categories),productIds:[2,3,999,4,1]};
assert.deepEqual(featuredProducts(config,products,categories).map(p=>p.id),[2,1]);
assert.deepEqual(featuredProducts({...config,productIds:[]},products,categories),[]);
assert.equal(featuredConfig({featuredCards:{visible:false,productIds:[]}},products).visible,false);
assert.ok(featuredBackground({...config,backgroundStart:'#112233'}).background.includes('#112233'));
let persisted,stored,receivedRevision;
const store=Vue.reactive({products,categories,settings:{}}),connection={revision:12};
const source=readFileSync(new URL('../src/FeaturedCardsManager.js',import.meta.url),'utf8').replace(/^import .*;\r?\n/gm,'').replace('export default','return');
function manager(connected=false){return Function('computed','ref','store','connection','saveAdmin','connected','prepareSlide','FEATURED_KEY','featuredConfig','featuredProducts','FeaturedCards','localStorage',source)(Vue.computed,Vue.ref,store,connection,async(key,next,revision)=>{assert.equal(key,'featuredCards');persisted=next;receivedRevision=revision;},connected,async()=>({src:'cards/background.webp'}),FEATURED_KEY,featuredConfig,featuredProducts,{template:'<div></div>'}, {setItem(key,value){assert.equal(key,FEATURED_KEY);stored=JSON.parse(value);}});}
const c=manager(),draft=c.setup();
draft.move(1,-1);assert.deepEqual([...draft.draft.value.productIds],[2,1]);
draft.remove(0);draft.productId.value=2;draft.add();assert.deepEqual([...draft.draft.value.productIds],[1,2]);
draft.draft.value.title='New title';draft.draft.value.interval=0;draft.draft.value.visible=false;
assert.equal(store.settings.featuredCards,undefined,'Draft edits must not affect the application before save');
await draft.save();assert.equal(stored.title,'New title');assert.equal(store.settings.featuredCards.visible,false);
draft.draft.value.interval=500;await draft.save();assert.ok(draft.error.value);assert.equal(store.settings.featuredCards.interval,0);
draft.reset();assert.equal(draft.draft.value.interval,0);
const remote=manager(true).setup();connection.revision=13;await remote.save();assert.equal(receivedRevision,12,'Save must use the revision from when editing started');assert.equal(persisted.title,'New title');
const app=Vue.createSSRApp({...c,setup:()=>draft});app.directive('permit',{});app.directive('readonly-access',{});
const warnings=[];app.config.warnHandler=m=>warnings.push(m);const {renderToString}=require('@vue/server-renderer');
await renderToString(app);assert.deepEqual(warnings,[]);
console.log('Featured cards defaults, filtering, ordering, draft isolation, persistence, validation, revision and manager rendering passed.');

// Exercise timer lifecycle without waiting for wall-clock intervals.
const {runInNewContext}=await import('node:vm');
const deckSource=readFileSync(new URL('../src/CardDeck.js',import.meta.url),'utf8').replace(/^import .*;\r?\n/gm,'').replace('export default','const component=');
let mount,unmount,tick,delay,intervalChanged,timerCount=0;
const deckComponent=runInNewContext(deckSource+';component',{
  t:v=>v,language:{value:'ar'},ref:Vue.ref,computed:Vue.computed,
  watch(getter,callback){if(getter()===3200)intervalChanged=callback;},
  onMounted(fn){mount=fn;},onUnmounted(fn){unmount=fn;},
  ChevronUp:{},ChevronDown:{},deckSwipeStep:()=>0,document:{hidden:false},
  matchMedia:()=>({matches:false,addEventListener(){},removeEventListener(){}}),
  setInterval(fn,ms){tick=fn;delay=ms;timerCount++;return 1;},clearInterval(){tick=null;}
});
const props={products,interval:3200},deck=deckComponent.setup(props,{emit(){}});mount();
assert.equal(delay,3200);tick();assert.equal(deck.active.value,1);
props.interval=8000;intervalChanged();assert.equal(delay,8000);
props.interval=0;intervalChanged();assert.equal(tick,null);assert.equal(timerCount,2);
deck.move(1);assert.equal(deck.active.value,2,'Manual navigation still works with autoplay stopped');
props.interval=1000;intervalChanged();deck.hovered.value=true;tick();assert.equal(deck.active.value,2);
deck.hovered.value=false;tick();assert.equal(deck.active.value,3);unmount();assert.equal(tick,null);
console.log('Configured autoplay interval changes, stopping, manual controls, hover pause and cleanup passed.');
