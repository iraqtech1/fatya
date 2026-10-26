import {t,language,setLanguage} from './i18n.js';
import {Languages} from 'lucide-vue-next';
import ThemeToggle from './ThemeToggle.js';

export default {
 components:{Languages,ThemeToggle},
 props:{scope:{type:String,default:'web'}},
 setup(){return {t,language,setLanguage};},
 template:`<div class="web-preferences"><label class="web-language"><Languages :size="18" aria-hidden="true"/><select :value="language" @change="setLanguage($event.target.value)" :aria-label="t('اللغة')"><option value="ar" lang="ar">العربية</option><option value="ku" lang="ckb">کوردی</option><option value="en" lang="en">English</option></select></label><ThemeToggle :scope="scope"/></div>`
};
