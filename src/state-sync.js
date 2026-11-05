// Background synchronization yields to user actions and never overlaps requests.
export function startStateSync(refresh,{document=globalThis.document,setTimeout=globalThis.setTimeout,clearTimeout=globalThis.clearTimeout,interval=15000,maxDelay=120000}={}){
  let stopped=false,timer,running=false,delay=interval;
  function schedule(){clearTimeout(timer);if(!stopped&&!document.hidden)timer=setTimeout(tick,delay);}
  async function tick(){
    if(stopped||running||document.hidden)return;
    running=true;
    try{await refresh();delay=interval;}catch{delay=Math.min(delay*2,maxDelay);}
    finally{running=false;schedule();}
  }
  function visible(){clearTimeout(timer);if(!document.hidden)void tick();}
  document.addEventListener('visibilitychange',visible);
  schedule();
  return ()=>{stopped=true;clearTimeout(timer);document.removeEventListener('visibilitychange',visible);};
}
