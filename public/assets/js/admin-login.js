'use strict';
document.addEventListener('DOMContentLoaded',()=>{
 const {api,$,inputData}=window.Essenza;
 $('#adminLoginForm')?.addEventListener('submit',async e=>{
  e.preventDefault();const form=e.currentTarget;if(!form.reportValidity())return;
  const btn=form.querySelector('button');btn.disabled=true;$('#adminLoginError').textContent='';
  try{const result=await api('/api/admin/login',{method:'POST',data:inputData(form)});location.href=result.redirect;}
  catch(err){$('#adminLoginError').textContent=err.message;}finally{btn.disabled=false;}
 });
});
