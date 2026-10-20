import {normalizePhone} from './auth-preview.js';

export function limitPhoneInput(event){
  const value=normalizePhone(event.target.value).replace(/\D/g,'').slice(0,11);
  event.target.value=value;
  return value;
}
