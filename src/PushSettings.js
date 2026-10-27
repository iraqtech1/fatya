import {t} from './i18n.js';
import {connected} from './api.js';
import {pushState,togglePush} from './push-notifications.js';
import {Bell} from 'lucide-vue-next';
export default {
  components:{Bell},
  setup(){return {t,connected,pushState,togglePush};},
  template:`<div v-if="connected"><button class="account-row" :disabled="pushState.busy||!pushState.supported" role="switch" :aria-checked="pushState.enabled" @click="togglePush"><span class="account-row-icon"><Bell/></span><span>{{t(pushState.enabled?'إيقاف إشعارات الجهاز':'تفعيل إشعارات الجهاز')}}</span><small>{{t(!pushState.supported?'أضف الموقع للشاشة الرئيسية لتفعيل الإشعارات':pushState.busy?'لحظة':pushState.enabled?'مفعّلة':'غير مفعّلة')}}</small></button><p v-if="pushState.error" role="alert" class="auth-error">{{t(pushState.error)}}</p></div>`
};
