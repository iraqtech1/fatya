import {t,language} from './i18n.js';
import {ref,onMounted,onBeforeUnmount} from 'vue/dist/vue.esm-bundler.js';
import {useRegisterSW} from 'virtual:pwa-register/vue';
import {WifiOff} from 'lucide-vue-next';

export default {
  props:{allowInstall:{type:Boolean,default:true}},
  components:{WifiOff},
  setup(){
    const offline=ref(!navigator.onLine);
    let registration,timer;
    function checkUpdate(){if(navigator.onLine&&!document.hidden)registration?.update().catch(()=>{});}
    function network(){offline.value=!navigator.onLine;checkUpdate();}
    useRegisterSW({immediate:true,onRegisteredSW(_url,reg){registration=reg;checkUpdate();}});
    onMounted(()=>{window.addEventListener('online',network);window.addEventListener('offline',network);window.addEventListener('focus',checkUpdate);document.addEventListener('visibilitychange',checkUpdate);timer=setInterval(checkUpdate,60000);});
    onBeforeUnmount(()=>{window.removeEventListener('online',network);window.removeEventListener('offline',network);window.removeEventListener('focus',checkUpdate);document.removeEventListener('visibilitychange',checkUpdate);clearInterval(timer);});
    return {t,language,offline};
  },
  template:`<div class="pwa-controls"><div v-if="offline" class="pwa-offline" role="status"><WifiOff :size="15"/> {{t("بدون اتصال — تعرض النسخة المحفوظة")}}</div></div>`
};
