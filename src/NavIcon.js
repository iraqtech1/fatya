export default {
  props: { icon: [Object, Function], active: Boolean, motion: Number, kind: String },
  template: `<span :key="active?motion:'idle'" class="nav-symbol" aria-hidden="true">
    <svg v-if="active" class="nav-progress-ring" viewBox="0 0 48 48">
      <circle cx="24" cy="24" r="21" pathLength="100"/>
    </svg>

    <component :is="icon" :key="active?motion:'idle'" :size="21" class="nav-glyph" :class="kind?'nav-glyph-'+kind:undefined"/>
  </span>`
};
