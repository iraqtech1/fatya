import {spawn} from 'node:child_process';
import {artisan,start,stop,base} from './laravel-harness.mjs';
// Uses only the explicitly named disposable _test database guarded by the harness.
artisan('migrate:fresh','--force');artisan('fatya:seed');await start();
const vite=spawn(process.execPath,['node_modules/vite/bin/vite.js','--host','127.0.0.1','--port','5174','--strictPort'],{stdio:'inherit',env:{...process.env,FATYA_API_PROXY:base}});
console.log('Browser review: http://127.0.0.1:5174 | API: '+base+' | disposable MariaDB: '+process.env.FATYA_TEST_DATABASE);
let stopping=false;
async function finish(){if(stopping)return;stopping=true;vite.kill();await stop();}
process.on('SIGINT',finish);process.on('SIGTERM',finish);vite.on('exit',finish);
