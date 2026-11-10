'use strict';
document.addEventListener('DOMContentLoaded',()=>{
 const {api,$,$$,toast,inputData}=window.Essenza;
 const current=new URLSearchParams(location.search).get('return')||'minha-conta';
 const safeReturn=/^(minha-conta|agendar)$/.test(current)?current:'minha-conta';
 $$('[data-auth-tab]').forEach(tab=>tab.addEventListener('click',()=>{
   $$('[data-auth-tab]').forEach(t=>t.classList.toggle('active',t===tab));
   $('#loginForm').classList.toggle('hidden',tab.dataset.authTab!=='login');
   $('#registerForm').classList.toggle('hidden',tab.dataset.authTab!=='register');
   $('#authError').textContent='';
 }));
 for(const [sel,path] of [['#loginForm','/api/client/login'],['#registerForm','/api/client/register']]){
  $(sel)?.addEventListener('submit',async e=>{
   e.preventDefault();const form=e.currentTarget;if(!form.reportValidity())return;
   const btn=form.querySelector('button[type="submit"]');btn.disabled=true;$('#authError').textContent='';
   try{let data=inputData(form);if(path.includes('register'))data.consent=$('input[name="consent"]',form)?.checked;
    await api(path,{method:'POST',data});location.assign(window.ESSENZA.base+'/'+safeReturn);
   }catch(err){$('#authError').textContent=err.message;}
   finally{btn.disabled=false;}
  });
 }
});
