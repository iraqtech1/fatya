import {spawn} from 'node:child_process';
import {randomBytes} from 'node:crypto';
try{process.loadEnvFile('.env');}catch(e){if(e.code!=='ENOENT')throw e;}
const password=process.env.ADMIN_PASSWORD||randomBytes(18).toString('base64url');
if(!process.env.ADMIN_PASSWORD)console.log('Local admin: masal / '+password);
const children=[spawn(process.execPath,['--env-file-if-exists=.env','server/start.mjs'],{stdio:'inherit',env:{...process.env,ADMIN_PASSWORD:password,DEV_OTP:process.env.DEV_OTP||'true'}}),spawn(process.execPath,['node_modules/vite/bin/vite.js','--host','127.0.0.1'],{stdio:'inherit'})];
let stopping=false;
function stop(code=0){if(stopping)return;stopping=true;children.forEach(c=>c.kill());process.exitCode=code;}
children.forEach(c=>c.on('exit',code=>stop(code||0)));
process.on('SIGINT',()=>stop());process.on('SIGTERM',()=>stop());
