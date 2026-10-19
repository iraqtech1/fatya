// Official artwork extracted from the supplied green identity guide.
export default {props:{full:Boolean},setup(){return {base:import.meta.env.BASE_URL};},template:`<img class="store-brand-mark" :class="{'brand-full':full}" :src="base+'brand/'+(full?'fatya-logo-green.png':'fatya-app-icon-green.png')" alt="" aria-hidden="true" width="64" height="64" decoding="async" draggable="false"/>`};
