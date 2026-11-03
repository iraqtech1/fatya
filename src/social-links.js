export const socialFields=[
  {key:'facebookUrl',label:'Facebook'},
  {key:'twitterUrl',label:'Twitter'},
  {key:'instagramUrl',label:'Instagram'},
  {key:'linkedinUrl',label:'Linkedin'},
  {key:'youtubeUrl',label:'Youtube'},
  {key:'telegramUrl',label:'Telegram'},
  {key:'tiktokUrl',label:'Tiktok'},
  {key:'whatsappUrl',label:'Whatsapp'},
];
export const emptySocialLinks=Object.fromEntries(socialFields.map(({key})=>[key,'']));
export function validSocialUrl(value){try{const url=new URL(value);return ['https:','http:'].includes(url.protocol)&&!!url.hostname&&!url.username&&!url.password;}catch{return false;}}
