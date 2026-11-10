'use strict';
document.addEventListener('DOMContentLoaded',async()=>{
 const {api,$,$$,esc,money,niceDate,shortDate,toast,inputData,renderIcons}=window.Essenza;
 const panel=$('#accountContent');let view='appointments',bookings=[],profile={},favorites={services:[],staff:[]},catalog={services:[],staff:[]};
 const statuses={pending:'Pendente',confirmed:'Confirmado',completed:'Concluído',cancelled:'Cancelado',no_show:'Não compareceu'};
 const getItems=async()=>{
   const [b,p,f,c]=await Promise.all([api('/api/client/bookings'),api('/api/client/profile'),api('/api/client/favorites'),api('/api/catalog')]);
   bookings=b.bookings;profile=p.client;favorites=f;catalog=c;
 };
 function show(tab){view=tab;$$('[data-account]').forEach(b=>b.classList.toggle('active',b.dataset.account===tab));render();}
 function actionButtons(a){if(!['confirmed','pending'].includes(a.status))return '';
  return `<div class="appointment-card-actions"><a class="small-btn" href="${window.ESSENZA.base}/agendar?servico=${a.service_id}&reagendar=${a.id}">Solicitar reagendamento</a><button class="small-btn" data-cancel="${a.id}">Cancelar</button></div>`;
 }
 function bookingCard(a){return `<div class="appointment-card"><div><h3>${esc(a.service_name)} <span class="status-badge status-${esc(a.status)}">${esc(statuses[a.status]||a.status)}</span></h3><p><strong>${esc(shortDate(a.scheduled_date))} às ${esc(a.starts.slice(0,5))}</strong> • ${esc(a.staff_name)}</p><p>${esc(money(a.price_snapshot))} • ${a.duration_minutes} min • Código ${esc(a.confirmation_code)}</p></div>${actionButtons(a)}</div>`;}
 function renderAppointments(){
   const today=new Date();let yyyy=today.getFullYear(),mm=String(today.getMonth()+1).padStart(2,'0'),dd=String(today.getDate()).padStart(2,'0');const now=`${yyyy}-${mm}-${dd}`;
   const upcoming=bookings.filter(a=>a.scheduled_date>=now&&['pending','confirmed'].includes(a.status));
   const historic=bookings.filter(a=>!upcoming.includes(a));
   panel.innerHTML=`<div class="section-heading"><span class="eyebrow">SUA AGENDA</span><h2>Meus agendamentos</h2><p>Consulte, remarque ou cancele conforme as regras do salão.</p></div><a class="btn btn-primary" href="${window.ESSENZA.base}/agendar">+ Agendar novo horário</a><h3 class="slots-title">Próximos atendimentos (${upcoming.length})</h3>${upcoming.length?upcoming.map(bookingCard).join(''):'<div class="empty-state"><i data-lucide="calendar-heart"></i><p>Você ainda não tem agendamentos futuros.</p></div>'}<h3 class="slots-title">Histórico de atendimentos</h3>${historic.length?historic.map(bookingCard).join(''):'<p class="subtle">Nenhum atendimento no histórico.</p>'}`;
 }
 function renderFavorites(){
   const sf=new Set(favorites.services.map(x=>Number(x.id))),pf=new Set(favorites.staff.map(x=>Number(x.id)));
   const rows=(type,list,set)=>list.map(v=>`<div class="account-favorite"><span>${esc(v.name)}</span><button class="small-btn" data-fav-type="${type}" data-fav-id="${v.id}" data-favorite="${set.has(Number(v.id))?'0':'1'}">${set.has(Number(v.id))?'♥ Favorito':'♡ Favoritar'}</button></div>`).join('');
   panel.innerHTML=`<span class="eyebrow">SEUS PREFERIDOS</span><h2>Meus favoritos</h2><p>Salve os cuidados e profissionais que você mais gosta.</p><h3 class="slots-title">Serviços</h3>${rows('service',catalog.services,sf)}<h3 class="slots-title">Profissionais</h3>${rows('staff',catalog.staff,pf)}`;
 }
 function renderProfile(){panel.innerHTML=`<span class="eyebrow">SEUS DADOS</span><h2>Meu perfil</h2><p>Mantenha seus dados sempre atualizados.</p><form id="accountProfile" class="form-stack" style="max-width:540px"><label>Nome completo<input name="name" required maxlength="150" value="${esc(profile.name)}"></label><label>Telefone com DDD<input name="phone" type="tel" required value="${esc(profile.phone)}"></label><label>E-mail <span class="optional">opcional</span><input name="email" type="email" value="${esc(profile.email||'')}"></label><button class="btn btn-primary">Salvar alterações <i data-lucide="check"></i></button></form><p class="subtle">Para solicitar exportação ou exclusão de dados pessoais, consulte a Política de Privacidade.</p>`;}
 function render(){if(view==='appointments')renderAppointments();else if(view==='favorites')renderFavorites();else renderProfile();renderIcons();}
 $$('[data-account]').forEach(b=>b.addEventListener('click',()=>show(b.dataset.account)));
 panel.addEventListener('click',async ev=>{
  const cancel=ev.target.closest('[data-cancel]');
  if(cancel){if(!confirm('Deseja realmente cancelar este agendamento?'))return;
   cancel.disabled=true;try{await api(`/api/client/bookings/${cancel.dataset.cancel}/cancel`,{method:'POST',data:{}});toast('Agendamento cancelado.');await getItems();render();}catch(err){toast(err.message);}finally{cancel.disabled=false;}return;
  }
  const favorite=ev.target.closest('[data-fav-id]');
  if(favorite){favorite.disabled=true;try{await api('/api/client/favorites',{method:'POST',data:{type:favorite.dataset.favType,id:Number(favorite.dataset.favId),favorite:favorite.dataset.favorite==='1'}});
   const f=await api('/api/client/favorites');favorites=f;render();}catch(err){toast(err.message);}finally{favorite.disabled=false;}}
 });
 panel.addEventListener('submit',async ev=>{
  if(ev.target.id!=='accountProfile')return;ev.preventDefault();const form=ev.target;if(!form.reportValidity())return;
  const btn=form.querySelector('button');btn.disabled=true;
  try{let result=await api('/api/client/profile',{method:'PUT',data:inputData(form)});profile=result.client;toast('Dados atualizados com sucesso.');render();}catch(err){toast(err.message);}finally{btn.disabled=false;}
 });
 $('#clientLogout').addEventListener('click',async()=>{try{await api('/api/client/logout',{method:'POST',data:{}});location.href=window.ESSENZA.base+'/';}catch(err){toast(err.message);}});
 try{await getItems();render();}catch(err){panel.innerHTML=`<p class="form-error">${esc(err.message)}</p>`;}
});
