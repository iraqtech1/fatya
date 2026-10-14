export const emptyPartnership=()=>({company:'',name:'',email:'',phone:'',website:'',type:'host',users:'',message:''});
export function validatePartnership(form){
 const errors={};
 for(const key of ['company','name'])if(form[key].trim().length<2)errors[key]='أدخل اسماً من حرفين على الأقل.';
 if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email.trim()))errors.email='أدخل بريداً إلكترونياً صحيحاً.';
 if(!/^\+?[\d ()-]{7,22}$/.test(form.phone.trim()) || form.phone.replace(/\D/g,'').length<7)errors.phone='أدخل رقم هاتف صحيحاً بالأرقام الإنجليزية.';
 if(form.website.trim()){try{const url=new URL(form.website.trim());if(!['http:','https:'].includes(url.protocol))throw Error()}catch{errors.website='أدخل رابطاً يبدأ بـ https:// أو http://.'}}
 if(!['host','merchant','distributor'].includes(form.type))errors.type='اختر نوع الشراكة.';
 if(!['under10k','10k100k','100k1m','over1m'].includes(form.users))errors.users='اختر حجم قاعدة المستخدمين.';
 return errors;
}
