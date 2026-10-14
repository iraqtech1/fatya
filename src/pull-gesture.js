export const refreshThreshold=68;
export function pullDistance(dx,dy){
  if(dy<=0||Math.abs(dx)>=dy)return 0;
  return Math.min(96,dy*.45);
}
