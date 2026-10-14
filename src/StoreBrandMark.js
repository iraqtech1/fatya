// Storefront-only identity. The dashboard continues to use MasalMark.
export default {props:{full:Boolean},setup(){return {base:import.meta.env.BASE_URL};},template:`<img class="store-brand-mark" :src="base+'brand/'+(full?'fatya-logo.png':'fatya-app-icon.png')" alt="" aria-hidden="true" width="64" height="64" decoding="async" draggable="false"/>`};
