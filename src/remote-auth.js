import {api} from './api.js';
export function createRemoteAuth(){
  let challengeId='';
  return {
    async login(data){return api('/auth/login',data);},
    async register(data){return api('/auth/register',data);},
    async start(data){const challenge=await api('/auth/start',data);challengeId=challenge.challengeId;return challenge;},
    async resetPassword(code,password){const result=await api('/auth/reset-password',{challengeId,code,password});challengeId='';return result;},
    async verify(code){return api('/auth/verify',{challengeId,code});},
    async resend(){const challenge=await api('/auth/resend',{challengeId});challengeId=challenge.challengeId;return challenge;},
    cancel(){challengeId='';},
    async updateProfile(user){return api('/auth/profile',{name:user.name,email:user.email},'PUT');},
  };
}
