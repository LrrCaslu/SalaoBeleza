'use strict';
document.addEventListener('DOMContentLoaded',()=>{
 const {api,$,$$,esc,money,shortDate,niceDate,toast,renderIcons,inputData}=window.Essenza;
 const root=window.ESSENZA.base;
 const canEdit=['owner','manager'].includes(window.ADMIN.role);
 const content=$('#adminContent'),modal=$('#adminDialog');
 const statusLabel={pending:'Pendente',confirmed:'Confirmado',completed:'Concluído',cancelled:'Cancelado',no_show:'Não compareceu'};
 const weekNames=['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'];
 const iso=d=>`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
 const state={tab:'dashboard',view:'week',date:window.ADMIN.today||iso(new Date()),filterStaff:'',filterService:'',filterStatus:'',appointments:[],staff:[],services:[],categories:[],clients:[],blocks:[],settings:null,search:'',catalog:null};
 const today=()=>window.ADMIN.today||iso(new Date());
 const toDate=s=>{const [y,m,d]=s.slice(0,10).split('-').map(Number);return new Date(y,m-1,d);};
 const addDays=(day,n)=>{let d=toDate(day);d.setDate(d.getDate()+n);return iso(d);};
 const imageUrl=(v)=>/^https:\/\//.test(v||'')?v:`${root}/assets/img/${String(v||'hair.webp').split('/').map(encodeURIComponent).join('/')}`;
 const heading=(eyebrow,title,subtitle)=>`<div class="section-heading"><span class="eyebrow">${esc(eyebrow)}</span><h1>${esc(title)}</h1><p>${esc(subtitle)}</p></div>`;
 const badge=s=>`<span class="status-badge status-${esc(s)}">${esc(statusLabel[s]||s)}</span>`;
 const opt=(value,label,selected)=>`<option value="${esc(value)}" ${String(selected)===String(value)?'selected':''}>${esc(label)}</option>`;
 const inputs=(label,name,value='',type='text',required=false)=>`<label>${esc(label)}<input type="${type}" name="${name}" value="${esc(value??'')}" ${type==='number'&&name==='price'?'step="0.01"':''} ${required?'required':''}></label>`;
 const fieldArea=(label,name,value='',rows=3)=>`<label class="wide">${esc(label)}<textarea name="${name}" rows="${rows}" maxlength="3000">${esc(value??'')}</textarea></label>`;
 function table(headers,rows,empty='Nenhum registro encontrado.'){
  return `<div class="admin-table-wrap"><table class="admin-table"><thead><tr>${headers.map(h=>`<th>${esc(h)}</th>`).join('')}</tr></thead><tbody>${rows||`<tr><td colspan="${headers.length}" class="admin-empty">${esc(empty)}</td></tr>`}</tbody></table></div>`;
 }
 function modalOpen(title,html,onSubmit){
  $('#adminDialogTitle').textContent=title;
  $('#adminDialogBody').innerHTML=`<form id="adminModalForm" class="form-stack">${html}<p class="form-error" id="modalError" role="alert"></p><button class="btn btn-primary" type="submit">Salvar informações <i data-lucide="check"></i></button></form>`;
  modal.showModal();renderIcons();
  $('#adminModalForm').addEventListener('submit',async e=>{
   e.preventDefault();const form=e.currentTarget;if(!form.reportValidity())return;
   const btn=form.querySelector('button[type="submit"]');btn.disabled=true;$('#modalError').textContent='';
   try{await onSubmit(form);modal.close();toast('Alterações salvas com sucesso.');await refresh();}
   catch(err){$('#modalError').textContent=err.message;}finally{btn.disabled=false;}
  });
 }
 $('#closeAdminDialog').onclick=()=>modal.close();
 modal.addEventListener('click',e=>{if(e.target===modal)modal.close();});
 $('#adminMenu')?.addEventListener('click',()=>$('.admin-side').classList.toggle('open'));
 $$('[data-admin-tab]').forEach(btn=>btn.addEventListener('click',()=>{
  state.tab=btn.dataset.adminTab;
  $$('[data-admin-tab]').forEach(b=>b.classList.toggle('active',b===btn));
  $('#adminBreadcrumb').textContent=btn.textContent.trim();
  $('.admin-side').classList.remove('open');refresh();
 }));
 $('#adminLogout').addEventListener('click',async()=>{try{await api('/api/admin/logout',{method:'POST',data:{}});location.href=root+'/admin/login';}catch(err){toast(err.message);}});
 async function ensureCatalog(){if(!state.catalog)state.catalog=await api('/api/catalog');return state.catalog;}
 async function refresh(){
  content.innerHTML='<p class="loading-inline">Atualizando informações do salão...</p>';
  try{
   switch(state.tab){
    case 'dashboard':await showDashboard();break;
    case 'appointments':await showAppointments();break;
    case 'services':await showServices();break;
    case 'staff':await showStaff();break;
    case 'clients':await showClients();break;
    case 'settings':await showSettings();break;
   }
  }catch(err){content.innerHTML=`<div class="admin-panel"><p class="form-error">${esc(err.message)}</p><button class="small-btn" id="retryAdmin">Tentar novamente</button></div>`;$('#retryAdmin').onclick=refresh;}
  renderIcons();
 }
 async function showDashboard(){
  const [data,bookings]=await Promise.all([api('/api/admin/dashboard'),api('/api/admin/appointments',{params:{from:today(),to:today()}})]);
  state.appointments=bookings.appointments;const m=data.metrics||{};let max=Math.max(1,...data.popular.map(x=>Number(x.total)));
  content.innerHTML=heading('VISÃO GERAL','Olá, Equipe Essenza','Acompanhe os agendamentos, atendimentos e resultados do seu studio.')+
   `<div class="dashboard-metrics"><div class="metric-card"><span class="metric-icon"><i data-lucide="calendar-days"></i> Agendamentos do dia</span><strong>${m.appointments_today||0}</strong><small>Para hoje</small></div><div class="metric-card"><span class="metric-icon"><i data-lucide="clock-3"></i> Próximos atendimentos</span><strong>${data.upcoming.length}</strong><small>Próximos na agenda</small></div><div class="metric-card"><span class="metric-icon"><i data-lucide="hourglass"></i> Pendentes</span><strong>${m.pending||0}</strong><small>Aguardando confirmação</small></div><div class="metric-card"><span class="metric-icon"><i data-lucide="wallet"></i> Faturamento registrado</span><strong style="font-size:27px">${esc(money(m.revenue))}</strong><small>Serviços marcados como concluídos</small></div></div>`+
   `<div class="dashboard-columns"><section class="admin-panel"><h2>Atendimentos de hoje</h2>${renderAppointmentTable(bookings.appointments,true)}</section><section class="admin-panel"><h2>Serviços mais procurados</h2>${data.popular.length?data.popular.map(v=>`<div class="mini-bar"><div class="mini-bar-head"><span>${esc(v.name)}</span><strong>${v.total}</strong></div><div class="mini-track"><div class="mini-fill" style="width:${Math.round(100*Number(v.total)/max)}%"></div></div></div>`).join(''):'<p class="admin-empty">Aparecerão aqui após os primeiros agendamentos.</p>'}</section></div>`+
   `<div class="dashboard-columns"><section class="admin-panel"><h2>Próximos atendimentos</h2>${table(['Data','Horário','Cliente','Serviço','Status'],data.upcoming.map(a=>`<tr><td>${shortDate(a.scheduled_date)}</td><td>${esc(a.starts.slice(0,5))}</td><td>${esc(a.client_name)}</td><td>${esc(a.service_name)}</td><td>${badge(a.status)}</td></tr>`).join(''))}</section><section class="admin-panel"><h2>Indicadores</h2><p class="eyebrow">BASE DE CLIENTES</p><h3 style="font-size:36px;margin-top:16px">${m.customers||0} <small style="font-size:13px">clientes cadastrados</small></h3><p class="status-note">Os indicadores são calculados a partir dos dados reais armazenados no MySQL.</p><button class="btn btn-outline" data-goto="appointments">Abrir agenda <i data-lucide="arrow-right"></i></button></section></div>`;
 }
 function renderAppointmentTable(apts,compact=false){
  return table(['Data','Hora','Cliente','Serviço','Profissional','Status','Ações'],apts.map(a=>`<tr><td>${shortDate(a.scheduled_date)}</td><td class="cell-primary">${esc(a.starts.slice(0,5))}</td><td>${esc(a.client_name)}</td><td>${esc(a.service_name)}</td><td>${esc(a.staff_name)}</td><td>${badge(a.status)}</td><td><div style="display:flex;gap:5px;align-items:center">${compact?'':`<select data-change-status="${a.id}" aria-label="Alterar status"><option value="">Alterar status</option>${Object.entries(statusLabel).map(([v,l])=>opt(v,l,a.status)).join('')}</select>`}<button class="small-btn" data-reschedule="${a.id}">Mover</button><button class="small-btn" data-whatsapp="${a.id}" title="Abre o WhatsApp; não envia automaticamente">WhatsApp</button></div></td></tr>`).join(''),'Nenhum atendimento neste período.');
 }
 function range(){
  const date=toDate(state.date);
  if(state.view==='day')return [state.date,state.date];
  if(state.view==='week'){
   const start=new Date(date);start.setDate(date.getDate() - ((date.getDay()+6)%7));
   const end=new Date(start);end.setDate(start.getDate()+6);return [iso(start),iso(end)];
  }
  return [iso(new Date(date.getFullYear(),date.getMonth(),1)),iso(new Date(date.getFullYear(),date.getMonth()+1,0))];
 }
 function calendarMarkup(apts){
  const [from,to]=range();let days=[];
  for(let d=from;d<=to;d=addDays(d,1))days.push(d);
  const aByDay={};apts.forEach(a=>{(aByDay[a.scheduled_date]??=[]).push(a);});
  const cells=days.map(date=>`<div class="calendar-column${date===today()?' today':''}"><h4>${esc(weekNames[toDate(date).getDay()])} • ${shortDate(date)}</h4>${(aByDay[date]||[]).map(a=>`<div class="calendar-entry status-${esc(a.status)}"><span>${esc(a.starts.slice(0,5))} · ${esc(a.service_name)}</span>${esc(a.client_name)}<br><small>${esc(a.staff_name)}</small></div>`).join('')}</div>`).join('');
  return `<div class="${state.view==='day'?'day-grid':state.view==='week'?'week-grid':'month-grid'}">${cells}</div>`;
 }
 async function showAppointments(){
  const [from,to]=range();
  const [data,staff,blocks]=await Promise.all([
   api('/api/admin/appointments',{params:{from,to,staff_id:state.filterStaff,service_id:state.filterService,status:state.filterStatus}}),
   api('/api/admin/staff'),api('/api/admin/blocks',{params:{from}})
  ]);
  state.appointments=data.appointments;state.staff=staff.staff;state.blocks=blocks.blocks;
  const staffOpts='<option value="">Todos os profissionais</option>'+state.staff.map(s=>opt(s.id,s.name,state.filterStaff)).join('');
  const fullCatalog=await ensureCatalog();
  const serviceOpts='<option value="">Todos os serviços</option>'+fullCatalog.services.map(s=>opt(s.id,s.name,state.filterService)).join('');
  const statusOpts='<option value="">Todos os status</option>'+Object.entries(statusLabel).map(([k,v])=>opt(k,v,state.filterStatus)).join('');
  content.innerHTML=heading('AGENDA','Agendamentos','Visualize a agenda, confirme horários, reagende e bloqueie períodos.')+
   `<div class="admin-toolbar"><input type="date" id="agendaDate" value="${state.date}" aria-label="Data de referência"><select id="agendaView"><option value="day" ${state.view==='day'?'selected':''}>Dia</option><option value="week" ${state.view==='week'?'selected':''}>Semana</option><option value="month" ${state.view==='month'?'selected':''}>Mês</option></select><select id="agendaStaff">${staffOpts}</select><select id="agendaService">${serviceOpts}</select><select id="agendaStatus">${statusOpts}</select><button class="btn btn-outline" id="addBlock"><i data-lucide="ban"></i> Bloquear período</button><button class="btn btn-primary push-right" id="newBooking"><i data-lucide="plus"></i> Novo agendamento</button></div>`+
   `<div class="admin-panel"><h2>Calendário • ${shortDate(from)} a ${shortDate(to)}</h2>${calendarMarkup(data.appointments)}</div>`+
   `<div class="admin-panel"><h2>Agendamentos (${data.appointments.length})</h2>${renderAppointmentTable(data.appointments)}</div>`+
   `<div class="admin-panel"><h2>Bloqueios programados</h2>${table(['Data','Profissional','Horário','Motivo','Ação'],state.blocks.map(b=>`<tr><td>${shortDate(b.block_date)}</td><td>${esc(b.staff_name)}</td><td>${esc(b.starts.slice(0,5))}–${esc(b.ends.slice(0,5))}</td><td>${esc(b.reason)}</td><td><button class="small-btn" data-delete-block="${b.id}">Remover</button></td></tr>`).join(''),'Nenhum bloqueio para este período.')}</div>`;
  $('#agendaDate').onchange=e=>{state.date=e.target.value;refresh();};
  $('#agendaView').onchange=e=>{state.view=e.target.value;refresh();};
  $('#agendaStaff').onchange=e=>{state.filterStaff=e.target.value;refresh();};
  $('#agendaService').onchange=e=>{state.filterService=e.target.value;refresh();};
  $('#agendaStatus').onchange=e=>{state.filterStatus=e.target.value;refresh();};
  $('#newBooking').onclick=()=>openNewAppointment();
  $('#addBlock').onclick=()=>openBlock();
 }
 async function openNewAppointment(old=null){
  const [cat,c]=await Promise.all([ensureCatalog(),api('/api/admin/clients')]);
  const clients=c.clients;const s=old?cat.services.find(x=>Number(x.id)===Number(old.service_id)):null;
  const clientSelect=`<label>Cliente<select name="client_id" required><option value="">Selecione um cliente</option>${clients.map(x=>opt(x.id,`${x.name} · ${x.phone}`,old?.client_id||'')).join('')}</select></label>`;
  const serviceSelect=`<label>Serviço<select name="service_id" id="modalService" required><option value="">Selecione um serviço</option>${cat.services.map(x=>opt(x.id,`${x.name} — ${money(x.price)}`,old?.service_id||'')).join('')}</select></label>`;
  const personSelect=`<label>Profissional<select name="staff_id" id="modalStaff"><option value="0">Qualquer disponível</option>${cat.staff.map(p=>opt(p.id,p.name,old?.staff_id||'')).join('')}</select></label>`;
  const html=`<div class="admin-form-grid">${!old?clientSelect:''}${!old?serviceSelect:`<p class="wide">${esc(s?.name||'Serviço')} — ${esc(old.client_name)}</p>`}${personSelect}${inputs('Data','date',old?.scheduled_date||state.date,'date',true)}<label>Horário disponível<select name="time" id="modalTime" required><option value="">Selecione serviço e data</option></select></label>${!old?fieldArea('Observações','notes','',2):''}</div><p class="status-note">Os horários são consultados no servidor; a reserva é validada novamente ao salvar.</p>`;
  modalOpen(old?'Reagendar atendimento':'Novo agendamento',html,async form=>{
   const d=inputData(form);
   if(old){await api(`/api/admin/appointments/${old.id}/reschedule`,{method:'POST',data:{staff_id:Number(d.staff_id||0),date:d.date,time:d.time}});}
   else{await api('/api/admin/appointments',{method:'POST',data:{client_id:Number(d.client_id),service_id:Number(d.service_id),staff_id:Number(d.staff_id||0),date:d.date,time:d.time,notes:d.notes||''}});}
  });
  const form=$('#adminModalForm'),svc=$('#modalService'),staff=$('#modalStaff'),date=$('[name="date"]',form),time=$('#modalTime');
  async function updateTimes(){
   const serviceId=old?.service_id||svc?.value;
   if(!serviceId||!date.value){time.innerHTML='<option value="">Selecione serviço e data</option>';return;}
   time.innerHTML='<option value="">Consultando...</option>';
   try{let data=await api('/api/availability',{params:{service_id:serviceId,staff_id:staff.value,date:date.value}});
    time.innerHTML='<option value="">Selecione um horário</option>'+data.slots.map(x=>opt(x.time,x.time,old?.starts?.slice(0,5)||'')).join('');
    if(!data.slots.length)time.innerHTML='<option value="">Nenhum horário disponível</option>';
   }catch(err){time.innerHTML='<option value="">'+esc(err.message)+'</option>';}
  }
  svc?.addEventListener('change',updateTimes);staff.onchange=updateTimes;date.onchange=updateTimes;
  updateTimes();
 }
 async function openBlock(){
  if(!state.staff.length){const response=await api('/api/admin/staff');state.staff=response.staff;}
  modalOpen('Bloquear horário',`<div class="admin-form-grid"><label>Profissional<select name="staff_id" required>${state.staff.map(x=>opt(x.id,x.name,'')).join('')}</select></label>${inputs('Data','date',state.date,'date',true)}${inputs('De','starts','12:00','time',true)}${inputs('Até','ends','13:00','time',true)}${inputs('Motivo','reason','Pausa interna')}</div>`,async form=>{
    const d=inputData(form);await api('/api/admin/blocks',{method:'POST',data:{...d,staff_id:Number(d.staff_id)}});
   });
 }
 async function showServices(){
  const result=await api('/api/admin/services');state.services=result.services;state.categories=result.categories;
  content.innerHTML=heading('CATÁLOGO','Serviços','Defina tratamentos, categorias, duração e preços.')+
   `<div class="admin-toolbar">${canEdit?'<button class="btn btn-primary push-right" id="newService"><i data-lucide="plus"></i> Novo serviço</button>':''}</div>`+
   `<div class="admin-card-grid">${state.services.map(s=>`<article class="admin-service-card"><img src="${esc(imageUrl(s.image))}" alt=""><div><strong>${esc(s.name)}</strong><small>${esc(s.category)} · ${s.duration_minutes} min</small><small>${money(s.price)} · ${s.active?'Ativo':'Desativado'}</small>${canEdit?`<button class="small-btn" data-edit-service="${s.id}">Editar serviço</button>`:''}</div></article>`).join('')}</div>`;
  $('#newService')?.addEventListener('click',()=>openService());
 }
 function openService(id=0){
  const s=state.services.find(x=>Number(x.id)===Number(id))||{category_id:1,name:'',description:'',price:'',duration_minutes:60,image:'hair.webp',active:1};
  const form=`<div class="admin-form-grid">${inputs('Nome do serviço','name',s.name,'text',true)}<label>Categoria<select name="category_id">${state.categories.map(c=>opt(c.id,c.name,s.category_id)).join('')}</select></label>${inputs('Preço (R$)','price',s.price,'number',true)}${inputs('Duração em minutos','duration_minutes',s.duration_minutes,'number',true)}${inputs('Imagem (.webp local ou URL HTTPS)','image',s.image,'text',true)}<label>Enviar fotografia<input type="file" accept="image/jpeg,image/png,image/webp" data-upload-target="image"></label><label class="admin-check"><input name="active" type="checkbox" ${s.active?'checked':''}> Disponível para agendamento</label>${fieldArea('Descrição','description',s.description,3)}</div>`;
  modalOpen(id?'Editar serviço':'Adicionar serviço',form,async f=>{
   const d=inputData(f);d.category_id=Number(d.category_id);d.duration_minutes=Number(d.duration_minutes);d.price=Number(d.price);d.active=$('[name="active"]',f).checked;
   await api('/api/admin/services'+(id?'/'+id:''),{method:id?'PUT':'POST',data:d});
  });
 }
 async function showStaff(){
  const [staff,services]=await Promise.all([api('/api/admin/staff'),api('/api/admin/services')]);
  state.staff=staff.staff;state.services=services.services;
  content.innerHTML=heading('NOSSA EQUIPE','Profissionais','Gerencie especialidades, serviços atendidos, jornadas e folgas.')+
   `<div class="admin-toolbar">${canEdit?'<button class="btn btn-primary push-right" id="newStaff"><i data-lucide="plus"></i> Adicionar profissional</button>':''}</div>`+
   `<div class="admin-card-grid">${state.staff.map(p=>`<article class="admin-person-card"><img src="${esc(imageUrl(p.photo))}" alt=""><div><strong>${esc(p.name)}</strong><small>${esc(p.specialty)}</small><small>${p.active?'Ativo':'Inativo'} · ${p.service_ids.length} serviços</small>${canEdit?`<button class="small-btn" data-edit-staff="${p.id}">Editar</button> <button class="small-btn" data-day-off="${p.id}">Folgas</button>`:''}</div></article>`).join('')}</div>`;
  $('#newStaff')?.addEventListener('click',()=>openStaff());
 }
 function hoursMarkup(person){
  const hours=person.hours||[];
  return `<div class="hours-editor">${weekNames.map((day,i)=>{
   const h=hours.find(h=>Number(h.weekday)===i),enabled=h?!!Number(h.enabled):i!==0;
   return `<div class="hours-row" data-weekday="${i}"><strong>${day}</strong><input type="checkbox" class="work-enabled" ${enabled?'checked':''} aria-label="Trabalha ${day}"><input type="time" class="work-start" value="${h?.starts?.slice(0,5)||'09:00'}" title="Início"><input type="time" class="work-end" value="${h?.ends?.slice(0,5)||(i===6?'17:00':'19:00')}" title="Fim"><input type="time" class="break-start" value="${h?.break_starts?.slice(0,5)||'12:00'}" title="Pausa início"><input type="time" class="break-end" value="${h?.break_ends?.slice(0,5)||'13:00'}" title="Pausa fim"></div>`;
  }).join('')}</div><p class="status-note">Colunas: dia · ativo · início · fim · pausa início · pausa fim. Desmarque os dias de folga semanal.</p>`;
 }
 function openStaff(id=0){
  const p=state.staff.find(x=>Number(x.id)===Number(id))||{name:'',specialty:'',bio:'',photo:'staff-1.webp',active:1,service_ids:[],hours:[]};
  const services=state.services.map(s=>`<label class="admin-check"><input type="checkbox" class="staff-service" value="${s.id}" ${p.service_ids.some(x=>Number(x)===Number(s.id))?'checked':''}> ${esc(s.name)}</label>`).join('');
  const html=`<div class="admin-form-grid">${inputs('Nome completo','name',p.name,'text',true)}${inputs('Especialidade','specialty',p.specialty,'text',true)}${inputs('Imagem (.webp ou URL HTTPS)','photo',p.photo,'text',true)}<label>Enviar fotografia<input type="file" accept="image/jpeg,image/png,image/webp" data-upload-target="photo"></label><label class="admin-check"><input name="active" type="checkbox" ${p.active?'checked':''}> Profissional ativo</label>${fieldArea('Apresentação','bio',p.bio,3)}</div><h3>Serviços habilitados</h3><div class="admin-checkboxes">${services}</div><h3>Jornada semanal</h3>${hoursMarkup(p)}`;
  modalOpen(id?'Editar profissional':'Adicionar profissional',html,async form=>{
   const d=inputData(form);d.active=$('[name="active"]',form).checked;
   d.service_ids=$$('.staff-service:checked',form).map(x=>Number(x.value));
   d.hours=$$('[data-weekday]',form).map(row=>({weekday:Number(row.dataset.weekday),enabled:$('.work-enabled',row).checked,starts:$('.work-start',row).value,ends:$('.work-end',row).value,break_starts:$('.break-start',row).value||null,break_ends:$('.break-end',row).value||null}));
   await api('/api/admin/staff'+(id?'/'+id:''),{method:id?'PUT':'POST',data:d});
  });
 }
 function openDayOff(id){
  const p=state.staff.find(x=>Number(x.id)===Number(id));if(!p)return;
  modalOpen(`Folgas • ${p.name}`,`<p>Cadastre um dia de folga sem atendimentos ativos.</p><div class="admin-form-grid">${inputs('Data','date',today(),'date',true)}${inputs('Motivo','reason','Folga')}</div><ul class="data-list">${(p.days_off||[]).map(o=>`<li>${shortDate(o.off_date)} — ${esc(o.reason)} <button type="button" data-remove-dayoff="${p.id}|${o.off_date}">Remover</button></li>`).join('')}</ul>`,async f=>{const d=inputData(f);await api('/api/admin/staff-days-off',{method:'POST',data:{...d,staff_id:Number(id)}});});
 }
 async function showClients(){
  const result=await api('/api/admin/clients',{params:{search:state.search}});state.clients=result.clients;
  content.innerHTML=heading('RELACIONAMENTO','Clientes','Encontre clientes, consulte o histórico e realize novos cadastros.')+
   `<div class="admin-toolbar"><input id="searchClients" type="search" placeholder="Pesquisar nome, telefone ou e-mail" value="${esc(state.search)}" style="flex:1;min-width:210px"><button class="btn btn-outline" id="doSearch"><i data-lucide="search"></i> Pesquisar</button><button class="btn btn-primary push-right" id="newClient"><i data-lucide="user-plus"></i> Novo cliente</button></div>`+
   `<div class="admin-panel">${table(['Nome','Telefone','E-mail','Agendamentos','Ações'],state.clients.map(c=>`<tr><td class="cell-primary">${esc(c.name)}</td><td>${esc(c.phone)}</td><td>${esc(c.email||'—')}</td><td>${c.total_appointments}</td><td><button class="small-btn" data-view-client="${c.id}">Ver histórico</button></td></tr>`).join(''))}</div>`;
  $('#newClient').onclick=openNewClient;
  $('#doSearch').onclick=()=>{state.search=$('#searchClients').value.trim();refresh();};
  $('#searchClients').onkeydown=e=>{if(e.key==='Enter'){e.preventDefault();state.search=e.target.value.trim();refresh();}};
 }
 function openNewClient(){
  modalOpen('Novo cliente',`<p class="status-note">O cliente deverá ser informado sobre o cadastro e receber a senha definida de forma segura.</p><div class="admin-form-grid">${inputs('Nome completo','name','','text',true)}${inputs('Telefone/WhatsApp','phone','','tel',true)}${inputs('E-mail (opcional)','email','','email')}${inputs('Senha inicial segura (mín. 10 caracteres)','password','','password',true)}<label class="admin-check wide"><input type="checkbox" name="consent" required> O cliente consentiu com este cadastro</label></div>`,async f=>{
   const data=inputData(f);data.consent=$('[name="consent"]',f).checked;
   await api('/api/admin/clients',{method:'POST',data});
  });
 }
 async function openClientDetail(id){
  const data=await api(`/api/admin/clients/${id}`),c=data.client;
  const notes=(data.notes||[]).map(n=>`<li><span><strong>${esc(n.author)}</strong> · ${esc(shortDate(n.created_at))}<br>${esc(n.note)}</span>${canEdit?`<button type="button" data-remove-note="${c.id}|${n.id}">Excluir</button>`:''}</li>`).join('')||'<li>Nenhuma observação interna.</li>';
  modalOpen(`Cliente • ${c.name}`,`<p><strong>Telefone:</strong> ${esc(c.phone)}<br><strong>E-mail:</strong> ${esc(c.email||'—')}<br><strong>Cadastro:</strong> ${esc(shortDate(c.created_at))}</p><h3>Histórico de atendimentos</h3>${table(['Data','Serviço','Profissional','Status'],data.history.map(a=>`<tr><td>${shortDate(a.scheduled_date)}</td><td>${esc(a.service_name)}</td><td>${esc(a.staff_name)}</td><td>${badge(a.status)}</td></tr>`).join(''))}<h3>Observações internas autorizadas</h3><ul class="data-list">${notes}</ul><label>Nova observação<textarea name="note" rows="3" maxlength="700" placeholder="Apenas informações pertinentes ao atendimento"></textarea></label><label class="checkbox-line"><input type="checkbox" name="authorized" required> Confirmo que a cliente autorizou o registro desta observação.</label>`,async form=>{
   const d=inputData(form);if(!d.note.trim())throw new Error('Informe a observação.');
   await api(`/api/admin/clients/${id}/notes`,{method:'POST',data:{note:d.note,authorized:$('[name="authorized"]',form).checked}});
  });
 }
 function salonHoursMarkup(hours){
  return `<div class="hours-editor">${weekNames.map((day,i)=>{
   const h=hours.find(x=>Number(x.weekday)===i)||{};
   return `<div class="hours-row" data-salon-day="${i}"><strong>${day}</strong><input class="salon-closed" type="checkbox" ${h.closed?'':'checked'} aria-label="Aberto ${day}"><input class="salon-open" type="time" value="${h.opens?.slice(0,5)||'09:00'}"><input class="salon-close" type="time" value="${h.closes?.slice(0,5)||'19:00'}"><span></span><span></span></div>`;
  }).join('')}</div><p class="status-note">Colunas: dia · aberto · abertura · fechamento.</p>`;
 }
 async function showSettings(){
  const res=await api('/api/admin/settings');state.settings=res;
  const s=res.settings;
  const fields=`<div class="admin-form-grid">${inputs('Nome do salão','salon_name',s.salon_name,'text',true)}${inputs('Subtítulo','subtitle',s.subtitle)}${inputs('Título principal do site','hero_title',s.hero_title)}${inputs('Endereço','address',s.address)}${inputs('Cidade/UF','city',s.city)}${inputs('Telefone exibido','phone',s.phone)}${inputs('WhatsApp com DDI, somente números','whatsapp',s.whatsapp)}${inputs('E-mail','email',s.email,'email')}${inputs('Instagram (URL HTTPS)','instagram',s.instagram)}${inputs('Logotipo (.webp ou URL HTTPS)','logo',s.logo)}<label>Enviar logotipo<input type="file" accept="image/jpeg,image/png,image/webp" data-upload-target="logo"></label>${inputs('Cor da marca','brand_color',s.brand_color,'color')}${inputs('Fuso horário','timezone',s.timezone)}${inputs('Antecedência mínima (minutos)','booking_lead_minutes',s.booking_lead_minutes,'number')}${inputs('Agenda disponível (dias)','booking_horizon_days',s.booking_horizon_days,'number')}${inputs('Antecedência para cancelamento (horas)','cancellation_hours',s.cancellation_hours,'number')}${fieldArea('Descrição principal','hero_description',s.hero_description,3)}${fieldArea('Texto sobre o salão','about_text',s.about_text,4)}</div>`;
  content.innerHTML=heading('PERSONALIZAÇÃO','Configurações','Atualize a identidade visual, os horários e as políticas do salão.')+
  `<div class="settings-grid"><section class="admin-panel"><h2>Informações e conteúdo</h2><form id="settingsForm" class="form-stack">${fields}<h3>Horário de funcionamento</h3>${salonHoursMarkup(res.hours)}${canEdit?'<button class="btn btn-primary" type="submit">Salvar configurações <i data-lucide="check"></i></button>':'<p class="status-note">Seu perfil possui permissão somente de leitura.</p>'}</form></section>`+
  `<aside><section class="admin-panel"><h2>Feriados e fechamentos</h2><ul class="data-list">${res.closures.map(o=>`<li>${shortDate(o.closed_date)} • ${esc(o.reason)} ${canEdit?`<button data-closure-remove="${o.closed_date}">Excluir</button>`:''}</li>`).join('')||'<li>Nenhum dia fechado cadastrado.</li>'}</ul>${canEdit?`<form id="closureForm" class="form-stack"><label>Data<input name="date" type="date" required></label><label>Motivo<input name="reason" value="Feriado"></label><button class="btn btn-outline" type="submit">Adicionar fechamento</button></form>`:''}</section>`+
  `<section class="admin-panel"><h2>Galeria do site</h2><ul class="data-list">${res.gallery.map(o=>`<li><span><img src="${esc(imageUrl(o.image))}" alt="" style="width:45px;height:45px;object-fit:cover;border-radius:7px;display:inline-block;vertical-align:middle;margin-right:7px">${esc(o.caption)}</span>${canEdit?`<button data-gallery-remove="${o.id}">Excluir</button>`:''}</li>`).join('')}</ul>${canEdit?`<form id="galleryForm" class="form-stack"><label>Imagem local .webp ou URL HTTPS<input name="image" required placeholder="hair.webp"></label><label>Enviar imagem<input type="file" accept="image/jpeg,image/png,image/webp" data-upload-target="image"></label><label>Legenda<input name="caption" maxlength="150"></label><button type="submit" class="btn btn-outline">Adicionar foto</button></form>`:''}</section>`+
  `<section class="admin-panel"><h2>Comunicação</h2><p class="status-note">Os links abrem o WhatsApp manualmente. A API oficial do WhatsApp Business ainda não está configurada; nenhum lembrete automático é enviado.</p><p class="status-note">Para conformidade com a LGPD, personalize a política de privacidade antes da publicação.</p></section></aside></div>`;
  const settingsForm=$('#settingsForm');
  settingsForm?.addEventListener('submit',async e=>{
   e.preventDefault();if(!canEdit)return;
   const button=settingsForm.querySelector('button[type="submit"]');button.disabled=true;
   try{const d=inputData(settingsForm);
    d.hours=$$('[data-salon-day]',settingsForm).map(row=>({weekday:Number(row.dataset.salonDay),closed:!$('.salon-closed',row).checked,opens:$('.salon-open',row).value,closes:$('.salon-close',row).value}));
    await api('/api/admin/settings',{method:'PUT',data:d});toast('Configurações atualizadas. Recarregue o site para ver a nova identidade.');await refresh();
   }catch(err){toast(err.message);}finally{button.disabled=false;}
  });
  $('#closureForm')?.addEventListener('submit',async e=>{e.preventDefault();try{await api('/api/admin/closures',{method:'POST',data:inputData(e.currentTarget)});toast('Fechamento cadastrado.');await refresh();}catch(err){toast(err.message);}});
  $('#galleryForm')?.addEventListener('submit',async e=>{e.preventDefault();try{await api('/api/admin/gallery',{method:'POST',data:inputData(e.currentTarget)});toast('Imagem adicionada.');await refresh();}catch(err){toast(err.message);}});
 }
 content.addEventListener('click',async e=>{
  const target=e.target.closest('button');if(!target)return;
  try{
   if(target.dataset.goto){const btn=$(`[data-admin-tab="${target.dataset.goto}"]`);btn?.click();return;}
   if(target.dataset.editService){openService(Number(target.dataset.editService));return;}
   if(target.dataset.editStaff){openStaff(Number(target.dataset.editStaff));return;}
   if(target.dataset.dayOff){openDayOff(Number(target.dataset.dayOff));return;}
   if(target.dataset.viewClient){await openClientDetail(target.dataset.viewClient);return;}
   if(target.dataset.reschedule){const a=state.appointments.find(x=>Number(x.id)===Number(target.dataset.reschedule));if(a)await openNewAppointment(a);else toast('Abra a agenda para reagendar.');return;}
   if(target.dataset.whatsapp){const a=state.appointments.find(x=>Number(x.id)===Number(target.dataset.whatsapp));if(!a){toast('Abra a agenda para enviar mensagem.');return;}
    const phone=String(a.client_phone||'').replace(/\D/g,'');if(!phone){toast('Cliente sem telefone válido.');return;}
    const message=`Olá, ${a.client_name}! Aqui é da Essenza Beauty Studio. Sobre seu atendimento ${a.service_name}, dia ${shortDate(a.scheduled_date)}, às ${a.starts.slice(0,5)}. Podemos conversar?`;
    window.open(`https://wa.me/55${phone}?text=${encodeURIComponent(message)}`,'_blank','noopener,noreferrer');return;
   }
   if(target.dataset.deleteBlock){if(!confirm('Remover este bloqueio de horário?'))return;await api(`/api/admin/blocks/${target.dataset.deleteBlock}`,{method:'DELETE',data:{}});await refresh();toast('Bloqueio removido.');return;}
   if(target.dataset.closureRemove){if(!confirm('Remover fechamento do calendário?'))return;await api(`/api/admin/closures/${target.dataset.closureRemove}`,{method:'DELETE',data:{}});await refresh();toast('Dia reaberto.');return;}
   if(target.dataset.galleryRemove){if(!confirm('Remover imagem da galeria?'))return;await api(`/api/admin/gallery/${target.dataset.galleryRemove}`,{method:'DELETE',data:{}});await refresh();toast('Imagem removida.');return;}
  }catch(err){toast(err.message);}
 });
 // Status selector is handled only for admin appointments; changes are persisted on the server.
 content.addEventListener('change',async e=>{
  const el=e.target.closest('[data-change-status]');if(!el||!el.value)return;
  const id=Number(el.dataset.changeStatus),status=el.value;
  if(!confirm(`Alterar status para ${statusLabel[status]}?`)){el.value='';return;}
  el.disabled=true;
  try{await api(`/api/admin/appointments/${id}`,{method:'PATCH',data:{status}});toast('Status atualizado.');await refresh();}catch(err){toast(err.message);el.disabled=false;}
 });
 document.addEventListener('change',async e=>{
  const input=e.target.closest('[data-upload-target]');if(!input||!input.files?.[0])return;
  const form=input.closest('form'),name=input.dataset.uploadTarget,destination=form?.querySelector(`[name="${name}"]`);
  if(!destination)return;
  const file=input.files[0],payload=new FormData();payload.set('image',file);
  input.disabled=true;
  try{
   const result=await fetch(root+'/api/admin/upload-image',{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':$('meta[name="csrf-token"]').content},body:payload});
   const json=await result.json();if(!result.ok)throw new Error(json.error||'Falha ao enviar imagem.');
   destination.value=json.image;toast('Foto enviada. Salve as informações para aplicá-la.');
  }catch(err){toast(err.message);}finally{input.disabled=false;}
 });
 $('#adminDialogBody').addEventListener('click',async e=>{
  const note=e.target.closest('[data-remove-note]');
  if(note){const [client,id]=note.dataset.removeNote.split('|');if(!confirm('Excluir esta observação interna?'))return;
   try{await api(`/api/admin/clients/${client}/notes/${id}`,{method:'DELETE',data:{}});modal.close();toast('Observação excluída.');await refresh();}catch(err){toast(err.message);}return;}
  const target=e.target.closest('[data-remove-dayoff]');if(!target)return;
  const [staff,date]=target.dataset.removeDayoff.split('|');
  try{await api(`/api/admin/staff-days-off/${staff}/${date}`,{method:'DELETE',data:{}});modal.close();toast('Folga removida.');await refresh();}catch(err){toast(err.message);}
 });
 refresh();
});
