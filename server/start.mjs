import {createMasalServer} from './app.mjs';
const {server}=createMasalServer({dbPath:process.env.DB_PATH||'server/data/masal.sqlite'});
server.listen(Number(process.env.PORT||3001),process.env.HOST||'127.0.0.1',()=>console.log('Fatya server ready on port '+(process.env.PORT||3001)));
