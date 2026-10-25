import {writeFileSync} from 'node:fs';
import {initialProducts} from '../src/catalog.js';
import {categoriesForProducts} from '../src/categories.js';
import {defaults} from '../src/slide-defaults.js';
import {defaultSettings} from '../src/preview-settings.js';
writeFileSync(new URL('../backend/database/seeders/catalog.json',import.meta.url), JSON.stringify({
  categories:categoriesForProducts(initialProducts),
  products:initialProducts.map(p=>({...p,active:true,stock:p.values.map(()=>0)})),
  middleSlides:defaults,settings:defaultSettings
},null,2)+'\n');
