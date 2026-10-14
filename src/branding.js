// Upgrade legacy brand labels without changing session keys or credentials.
export const brandText=value=>String(value??'').replace(/ماسال/g,'فاتيا').replace(/\bmasal\b/gi,'Fatya');
export function normalizeBrandSettings(settings){
  for(const key of ['name','privacyText','aboutText'])if(typeof settings[key]==='string')settings[key]=brandText(settings[key]);
  return settings;
}
