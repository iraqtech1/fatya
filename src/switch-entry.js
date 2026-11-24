import {createApp} from 'vue/dist/vue.esm-bundler.js';
import SwitchEntry from './SwitchEntry.js';
import './switch-entry.css';

// Also remove unexpected query data when opening the built HTML directly.
if(window.location.search||window.location.hash)window.history.replaceState(null,'',window.location.pathname);
createApp(SwitchEntry).mount('#app');
