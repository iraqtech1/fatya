// The mobile PWA scrolls its main element; desktop/web scrolls the window.
// Reset both after replacing the long payment form with its short receipt.
export function revealCheckoutReceipt(heading,{doc=globalThis.document,win=globalThis.window}={}){
  heading?.focus?.({preventScroll:true});
  doc?.querySelector?.('.app-shell>main')?.scrollTo?.({top:0,left:0,behavior:'instant'});
  win?.scrollTo?.({top:0,left:0,behavior:'instant'});
}
