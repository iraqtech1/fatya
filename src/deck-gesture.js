export function deckSwipeStep(dx,dy){
  if(Math.max(Math.abs(dx),Math.abs(dy))<=35)return 0;
  if(Math.abs(dy)>=Math.abs(dx))return dy<0?1:-1;
  return dx>0?1:-1;
}
