'use strict';
const ROOT=window.ESSENZA?.base||'';
const $=(selector,root=document)=>root.querySelector(selector);
const $$=(selector,root=document)=>[...root.querySelectorAll(selector)];
const esc=(value='')=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
const money=(value)=>new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(Number(value)||0);
const niceDate=(value)=>{
  if(!value)return '—';let [y,m,d]=value.slice(0,10).split('-').map(Number);
  return new Intl.DateTimeFormat('pt-BR',{day:'2-digit',month:'long',year:'numeric'}).format(new Date(y,m-1,d));
};
const shortDate=(value)=>{if(!value)return '—';let [y,m,d]=value.slice(0,10).split('-');return `${d}/${m}/${y}`;};
function toast(message){let el=$('#toast');if(!el){alert(message);return;}el.textContent=message;el.classList.add('show');clearTimeout(window.toastTimer);window.toastTimer=setTimeout(()=>el.classList.remove('show'),4500);}
function renderIcons(){if(window.lucide?.createIcons)window.lucide.createIcons();}
async function api(path,{method='GET',data,params}={}){
  const url=new URL(ROOT+path,location.origin);
  if(params)for(const [k,v] of Object.entries(params))if(v!==undefined&&v!==null)url.searchParams.set(k,v);
  const headers={'Accept':'application/json'};
  if(method!=='GET'){headers['Content-Type']='application/json';headers['X-CSRF-Token']=$('meta[name="csrf-token"]')?.content||'';}
  let response;
  try{response=await fetch(url,{method,headers,credentials:'same-origin',body:data===undefined?undefined:JSON.stringify(data)});}catch(err){throw new Error('Não foi possível conectar ao servidor. Verifique sua conexão.');}
  let json;try{json=await response.json();}catch{throw new Error('Resposta inesperada do servidor.');}
  if(!response.ok)throw new Error(json.error||`Erro ${response.status}.`);
  if(json.csrf){setCsrf(json.csrf);}if(json.booking?.csrf)setCsrf(json.booking.csrf);
  return json;
}
function setCsrf(token){const meta=$('meta[name="csrf-token"]');if(meta)meta.content=token;}
function inputData(form){return Object.fromEntries(new FormData(form).entries());}
function listenForms(form,fn){form?.addEventListener('submit',async event=>{event.preventDefault();const btn=form.querySelector('button[type="submit"],button:not([type])');if(btn)btn.disabled=true;try{await fn(inputData(form));}catch(error){toast(error.message);}finally{if(btn)btn.disabled=false;}});}
function initBase(){
 const menu=$('#mobileToggle');menu?.addEventListener('click',()=>{let open=$('#nav-menu')?.classList.toggle('open');menu.setAttribute('aria-expanded',String(!!open));});
 $$('#nav-menu a').forEach(a=>a.addEventListener('click',()=>{$('#nav-menu')?.classList.remove('open');menu?.setAttribute('aria-expanded','false');}));
 const mapButton=$('.map-consent');mapButton?.addEventListener('click',()=>{
  const iframe=$('.map-frame iframe');if(!iframe)return;
  iframe.src=iframe.dataset.src;mapButton.remove();
 });
 const dialog=$('#galleryDialog');
 $$('[data-gallery]').forEach(btn=>btn.addEventListener('click',()=>{if(!dialog)return;dialog.querySelector('img').src=btn.dataset.gallery;dialog.querySelector('img').alt=btn.dataset.caption||'Foto ampliada';dialog.querySelector('p').textContent=btn.dataset.caption||'';dialog.showModal();}));
 $('.close-dialog',dialog||document)?.addEventListener('click',()=>dialog.close());
 dialog?.addEventListener('click',e=>{if(e.target===dialog)dialog.close();});
 renderIcons();
}
document.addEventListener('DOMContentLoaded',initBase);
window.Essenza={api,$,$$,esc,money,niceDate,shortDate,toast,renderIcons,inputData,setCsrf};
