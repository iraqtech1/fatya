import {ref,watch,computed} from 'vue/dist/vue.esm-bundler.js';
export default {
 props:{offer:Object},
 setup(props){const failed=ref([]);watch(()=>props.offer,()=>{failed.value=[];});const images=computed(()=>(props.offer.images||[]).filter(src=>!failed.value.includes(src)));return{failed,images};},
 template:`<div class="provider-image-gallery"><template v-if="images.length"><img v-for="src in images" :key="src" :src="src" :alt="offer.title" loading="lazy" decoding="async" referrerpolicy="no-referrer" @error="failed.push(src)"/></template><span v-else class="provider-image-unavailable">غير متوفرة</span></div>`
};
