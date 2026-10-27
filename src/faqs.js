export const defaultFaqs=[
 {id:'faq-cards',question:'وين ألكى الكارت بعد الشراء؟',answer:'بقسم طلباتي تگدر تشوف تفاصيل الطلب وتنسخ الكود'},
 {id:'faq-payments',question:'شنو طرق الدفع؟',answer:'طرق الدفع المتوفرة: Qi وزين كاش'},
 {id:'faq-prices',question:'هل الأسعار نهائية؟',answer:'تظهر قيمة الطلب كاملة قبل تأكيد الشراء'}
];
export function faqRows(settings){return Array.isArray(settings?.faqs)?settings.faqs:defaultFaqs.map(f=>({...f}));}
export function faqText(entry,key,translate){const original=defaultFaqs.find(f=>f.id===entry.id);return original?.[key]===entry[key]?translate(entry[key]):entry[key];}
