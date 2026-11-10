import {computed} from 'vue/dist/vue.esm-bundler.js';
import {t} from './i18n.js';
import CardDeck from './CardDeck.js';
import {featuredBackground,featuredDefaults} from './featured-cards.js';
export default {
  components:{CardDeck},
  props:{config:Object,products:Array,preview:Boolean},
  emits:['select'],
  setup(props){return {t,featuredDefaults,background:computed(()=>featuredBackground(props.config))};},
  template:`<div v-if="config.visible||preview" class="hero featured-cards-hero" :style="background"><div class="hero-copy"><span v-if="config.label" class="hero-label"><span></span> {{config.label===featuredDefaults.label?t(config.label):config.label}}</span><h2 v-if="config.title||config.accent">{{config.title===featuredDefaults.title?t(config.title):config.title}}<br v-if="config.title&&config.accent"/><span>{{config.accent===featuredDefaults.accent?t(config.accent):config.accent}}</span></h2></div><CardDeck v-if="products.length" :products="products" :interval="config.interval" @select="$emit('select',$event)"/><p v-else-if="preview">اختر البطاقات للمعاينة</p></div>`
};
