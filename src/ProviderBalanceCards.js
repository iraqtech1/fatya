import {ref,onMounted,onBeforeUnmount} from 'vue/dist/vue.esm-bundler.js';
import {Wallet} from 'lucide-vue-next';
import {connected} from './api.js';
import {loadProviderBalances} from './provider-balances.js';
import './provider-balances.css';
export default {
 components:{Wallet},
 setup(){const cards=ref([{id:'topup',label:'رصيد TOPUP',balance:null,note:''},{id:'rabiaa',label:'رصيد الرابعة',balance:null,note:''}]),loading=ref(connected);let busy=false,timer,disposed=false;
 async function refresh(){if(busy||!connected)return;busy=true;try{const next=await loadProviderBalances();if(!disposed)cards.value=next;}finally{busy=false;if(!disposed)loading.value=false;}}
 function visible(){if(!document.hidden)refresh();}
 onMounted(()=>{refresh();timer=setInterval(visible,60000);document.addEventListener('visibilitychange',visible);});
 onBeforeUnmount(()=>{disposed=true;clearInterval(timer);document.removeEventListener('visibilitychange',visible);});
 return{cards,loading,connected,format:v=>new Intl.NumberFormat('en-US',{maximumFractionDigits:3}).format(Number(v))};},
 template:`<section v-for="c in cards" :key="c.id" class="admin-panel admin-otp-card admin-provider-balance" :aria-label="c.label" :aria-busy="loading"><div><span>{{c.label}}</span><Wallet :size="20"/></div><strong dir="ltr">{{loading?'…':c.balance===null?'—':format(c.balance)}} <small v-if="c.balance!==null">IQD</small></strong><p>{{!connected?'يتوفر الرصيد على الاستضافة المتصلة':loading?'جاري جلب الرصيد…':c.note}}</p></section>`
};
