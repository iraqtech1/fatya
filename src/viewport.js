// Safari can ignore viewport zoom limits; keep multi-touch from scaling the app.
function preventZoom(event){if(event.cancelable)event.preventDefault();}
document.addEventListener('gesturestart',preventZoom,{passive:false});
document.addEventListener('gesturechange',preventZoom,{passive:false});
document.addEventListener('touchmove',event=>{if(event.touches.length>1)preventZoom(event);},{passive:false});
