import {cpSync,readdirSync} from 'node:fs';
// Copy only Vite output into Laravel's web root. Keep index.php and .htaccess.
for(const name of readdirSync(new URL('../dist/',import.meta.url))) {
  if(['index.php','.htaccess'].includes(name))throw Error('Unexpected build entry');
  cpSync(new URL('../dist/'+name,import.meta.url),new URL('../backend/public/'+name,import.meta.url),{recursive:true});
}
console.log('Vue PWA copied to backend/public. Serve this directory only.');
