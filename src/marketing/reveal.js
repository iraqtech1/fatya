import {onMounted,onBeforeUnmount,watch,nextTick} from 'vue';
export function useReveal(route){
 let observer=null,frame=0;
 function reset(){observer?.disconnect();cancelAnimationFrame(frame);frame=requestAnimationFrame(()=>{
   const elements=document.querySelectorAll('.ms-site .ms-reveal');
   if(window.matchMedia('(prefers-reduced-motion: reduce)').matches || !('IntersectionObserver' in window)){elements.forEach(el=>el.classList.add('ms-shown'));return}
   observer=new IntersectionObserver(entries=>{entries.forEach(({target,isIntersecting})=>{if(isIntersecting){target.classList.add('ms-shown');observer.unobserve(target)}})},{threshold:.08});
   elements.forEach((el,i)=>{el.style.setProperty('--ms-delay',`${Math.min(i%3*60,120)}ms`);el.classList.add('ms-animated');observer.observe(el)});
 })}
 onMounted(reset);watch(()=>route.fullPath,async()=>{await nextTick();reset()});onBeforeUnmount(()=>{observer?.disconnect();cancelAnimationFrame(frame)});
}
