import {t} from './i18n.js';
import PagedList from './PagedList.js';
import {Ticket} from 'lucide-vue-next';
import './ticket-history.css';
export function ticketCode(id){return '#'+String(id||'').replace(/^TICKET-/i,'').replace(/-/g,'').slice(-8).toUpperCase();}
export default {
  components:{PagedList,Ticket},
  props:{items:{type:Array,default:()=>[]}},
  setup(){return {t,ticketCode};},
  template:`<section v-if="items.length" class="ticket-history support-ticket-history" :aria-label="t('تذاكري')"><header class="support-ticket-heading"><h2><Ticket :size="21" aria-hidden="true"/>{{t('تذاكري')}}</h2><span>{{items.length}}</span></header><PagedList :items="items" :size="6" v-slot="{items:ticketRows}"><div class="support-ticket-grid"><article v-for="ticket in ticketRows" :key="ticket.id" class="support-ticket-card"><header class="support-ticket-meta"><span class="support-ticket-number" :title="ticket.id" :aria-label="t('رقم التذكرة')+' '+ticket.id"><Ticket :size="15" aria-hidden="true"/><b dir="ltr">{{ticketCode(ticket.id)}}</b></span><span class="support-ticket-status" :class="{'is-closed':ticket.status==='مغلقة'}">{{t(ticket.status)}}</span><time v-if="ticket.date" :datetime="ticket.date" dir="ltr">{{String(ticket.date).slice(0,10)}}</time></header><div class="support-ticket-field"><span class="support-ticket-label">{{t('عنوان الرسالة')}}</span><h3 dir="auto">{{ticket.subject}}</h3></div><div class="support-ticket-field"><span class="support-ticket-label">{{t('رسالتك')}}</span><p dir="auto">{{ticket.message}}</p></div><div v-if="ticket.reply" class="support-ticket-field support-ticket-answer"><span class="support-ticket-label">{{t('رد الإدارة:')}}</span><p dir="auto">{{ticket.reply}}</p></div><p v-else class="support-ticket-waiting">{{t('بانتظار رد الدعم')}}</p></article></div></PagedList></section>`
};
