import {defineAsyncComponent} from 'vue/dist/vue.esm-bundler.js';
import {t} from './i18n.js';

export function lazyPage(loader){
  return defineAsyncComponent({loader,delay:150,
    loadingComponent:{setup:()=>({t}),template:`<p role="status" style="padding:24px;text-align:center">{{t('جاري التحميل...')}}</p>`},
    errorComponent:{setup:()=>({t,reload:()=>window.location.reload()}),template:`<div role="alert" style="padding:24px;text-align:center"><p>{{t('تعذّر الاتصال بالخادم')}}</p><button type="button" @click="reload">{{t('إعادة المحاولة')}}</button></div>`},
    onError(_error,retry,fail,attempts){if(attempts<3&&globalThis.navigator?.onLine!==false)setTimeout(retry,attempts*1000);else fail();},
  });
}
