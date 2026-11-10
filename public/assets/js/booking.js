'use strict';
document.addEventListener('DOMContentLoaded',async()=>{
 const {api,$,$$,esc,money,niceDate,toast,renderIcons,inputData}=window.Essenza;
 const initial=window.BOOKING_INITIAL||{};
 const state={step:1,category:Number(initial.category)||0,serviceId:Number(initial.service)||0,staffId:Number(initial.professional)||0,date:'',time:'',month:'',days:[],slots:[],customer:null,client:null,mode:'register',notes:'',reschedule:Number(initial.reschedule)||0};
 let catalog={services:[],staff:[],categories:[],staff_services:[]};let fetchSerial=0;
 const content=$('#bookingContent'),error=$('#bookingError');
 const service=()=>catalog.services.find(s=>Number(s.id)===state.serviceId);
 const professional=()=>catalog.staff.find(s=>Number(s.id)===state.staffId);
 const choices=()=>catalog.staff.filter(p=>catalog.staff_services.some(x=>Number(x.staff_id)===Number(p.id)&&Number(x.service_id)===state.serviceId));
 const picture=(name)=>/^https:\/\//.test(name||'')?name:window.ESSENZA.base+'/assets/img/'+String(name||'hair.webp').split('/').map(encodeURIComponent).join('/');
 function selected(v){return v?' selected':'';}
 function dateLocal(y,m,d){return `${y}-${String(m).padStart(2,'0')}-${String(d).padStart(2,'0')}`;}
 function currentMonth(){return state.month||(new Date()).toISOString().slice(0,7);}
 function stepHeader(title,text){return `<span class="eyebrow">ETAPA ${state.step} DE 5</span><h2>${title}</h2><p class="intro">${text}</p>`;}
 function summary(){
   const s=service();const rows=[['Serviço',s?.name],['Profissional',state.staffId?professional()?.name:(s?'Qualquer disponível':null)],['Data',state.date?niceDate(state.date):null],['Horário',state.time||null],['Duração',s?`${s.duration_minutes} min`:null],['Investimento',s?money(s.price):null]];
   $('#bookingSummary').innerHTML=rows.filter(r=>r[1]).map(([a,b])=>`<div class="summary-line"><span>${esc(a)}</span><strong>${esc(b)}</strong></div>`).join('')||'<p>Escolha seu primeiro serviço para começar a sua experiência.</p>';
 }
 function render(){
  error.textContent='';
  $$('#stepper .step').forEach((node,i)=>{node.classList.toggle('current',i===state.step-1);node.classList.toggle('done',i<state.step-1);const span=$('span',node);span.textContent=i<state.step-1?'✓':String(i+1);});
  $('#bookingBack').hidden=state.step===1;
  const next=$('#bookingNext');next.innerHTML=state.step===5?'Confirmar agendamento <i data-lucide="check-circle-2"></i>':'Continuar <i data-lucide="arrow-right"></i>';
  next.disabled=state.step===1?!state.serviceId:state.step===3?!(state.date&&state.time):false;
  if(state.step===1)renderServices();
  if(state.step===2)renderProfessionals();
  if(state.step===3)renderCalendar();
  if(state.step===4)renderCustomer();
  if(state.step===5)renderConfirmation();
  summary();renderIcons();
 }
 function renderServices(){
  const opts=catalog.categories.map(c=>`<button type="button" class="filter-chip${state.category===Number(c.id)?' active':''}" data-category="${c.id}">${esc(c.name)}</button>`).join('');
  const list=catalog.services.filter(s=>(!state.category||Number(s.category_id)===state.category)&&(!state.reschedule||Number(s.id)===state.serviceId));
  content.innerHTML=stepHeader('Escolha o serviço','Qual cuidado combina com o seu momento? Selecione para conhecer valores e duração.')+
   `<div class="service-filters"><button class="filter-chip${!state.category?' active':''}" data-category="0">Todos</button>${opts}</div><div class="service-list">${list.map(s=>`<button type="button" class="service-option${selected(state.serviceId===Number(s.id))}" data-service="${s.id}" aria-pressed="${state.serviceId===Number(s.id)}"><img src="${esc(picture(s.image))}" alt=""><span class="option-content"><strong>${esc(s.name)}</strong><small>${esc(s.description)}</small><em>${esc(money(s.price))} &nbsp; • &nbsp; ${s.duration_minutes} min</em></span><span class="radio-circle"></span></button>`).join('')}</div>`;
 }
 function renderProfessionals(){
  const list=choices();
  content.innerHTML=stepHeader('Com quem deseja cuidar de você?','Escolha sua especialista preferida ou deixe que encontremos a primeira disponível.')+
   `<div class="staff-options"><button class="staff-option${selected(state.staffId===0)}" data-staff="0" aria-pressed="${state.staffId===0}"><i data-lucide="sparkles"></i><strong>Qualquer profissional</strong><small>Maior disponibilidade</small></button>${list.map(p=>`<button class="staff-option${selected(state.staffId===Number(p.id))}" data-staff="${p.id}" aria-pressed="${state.staffId===Number(p.id)}"><img src="${esc(picture(p.photo))}" alt=""><strong>${esc(p.name)}</strong><small>${esc(p.specialty)}</small></button>`).join('')}</div>`;
 }
 function monthDisplay(){let [y,m]=state.month.split('-').map(Number);return new Intl.DateTimeFormat('pt-BR',{month:'long',year:'numeric'}).format(new Date(y,m-1,1));}
 function renderCalendar(){
  content.innerHTML=stepHeader('Escolha a data e o horário','O calendário mostra dias e horários realmente disponíveis para o serviço selecionado.')+
   `<div class="calendar-head"><button data-month="-1" aria-label="Mês anterior"><i data-lucide="chevron-left"></i></button><h3 id="calendarMonth">${esc(monthDisplay())}</h3><button data-month="1" aria-label="Mês seguinte"><i data-lucide="chevron-right"></i></button></div><div class="calendar-grid" id="calendarGrid"><p class="loading-inline">Carregando calendário...</p></div><h3 class="slots-title">Horários disponíveis</h3><p class="slots-help" id="slotsHelp">Selecione um dia para consultar os horários.</p><div class="time-options" id="timeOptions"></div>`;
  loadDays();
 }
 function drawCalendar(){
  let [y,m]=state.month.split('-').map(Number),first=new Date(y,m-1,1),number=new Date(y,m,0).getDate();
  let days=['D','S','T','Q','Q','S','S'].map(d=>`<span class="weekday">${d}</span>`);
  for(let n=0;n<first.getDay();n++)days.push('<span class="calendar-blank"></span>');
  for(let d=1;d<=number;d++){
   const date=dateLocal(y,m,d),enabled=state.days.includes(date);
   days.push(`<button type="button" class="calendar-day${selected(state.date===date)}" data-day="${date}" ${enabled?'':'disabled'} aria-label="${date}" aria-pressed="${state.date===date}">${d}</button>`);
  }
  $('#calendarGrid').innerHTML=days.join('');
  if(!state.days.length)$('#slotsHelp').textContent='Não há horários livres neste mês. Avance para o próximo.';
  drawSlots();
 }
 async function loadDays(){
  const serial=++fetchSerial;$('#calendarGrid').innerHTML='<p class="loading-inline">Consultando disponibilidade...</p>';
  try{const result=await api('/api/available-days',{params:{service_id:state.serviceId,staff_id:state.staffId,month:state.month}});
   if(serial!==fetchSerial)return;state.days=result.days;
   if(state.date&&!state.days.includes(state.date)){state.date='';state.time='';state.slots=[];}
   drawCalendar();summary();
  }catch(err){if(serial===fetchSerial){$('#calendarGrid').innerHTML=`<p class="form-error">${esc(err.message)}</p>`;}}
 }
 async function loadSlots(date){
  const serial=++fetchSerial;state.date=date;state.time='';state.slots=[];$('#slotsHelp').textContent='Buscando horários livres...';$('#timeOptions').innerHTML='';
  drawCalendar();$('#slotsHelp').textContent='Buscando horários livres...';
  try{const result=await api('/api/availability',{params:{service_id:state.serviceId,staff_id:state.staffId,date}});
   if(serial!==fetchSerial)return;state.slots=result.slots;drawSlots();summary();
  }catch(err){if(serial===fetchSerial)$('#slotsHelp').textContent=err.message;}
 }
 function drawSlots(){
  const slot=$('#timeOptions'),help=$('#slotsHelp');if(!slot||!help)return;
  help.textContent=state.date?state.slots.length?`Horários livres para ${niceDate(state.date)}:`:'Sem horários disponíveis para este dia.':'Escolha uma data disponível.';
  slot.innerHTML=state.slots.map(s=>`<button type="button" class="time-chip${selected(state.time===s.time)}" data-time="${esc(s.time)}" aria-pressed="${state.time===s.time}">${esc(s.time)}</button>`).join('');
  $('#bookingNext').disabled=!(state.date&&state.time);
 }
 function renderCustomer(){
  if(state.client){
   content.innerHTML=stepHeader('Confira seus dados','Seus dados estão protegidos na sua conta Essenza.')+
    `<div class="profile-readonly"><strong>${esc(state.client.name)}</strong><small>${esc(state.client.phone)} ${state.client.email?' • '+esc(state.client.email):''}</small><p class="subtle">Precisa atualizar? <a class="text-link" href="${window.ESSENZA.base}/minha-conta">Editar meu perfil</a></p></div><div class="form-stack"><label>Observações (opcional)<textarea id="bookingNotes" maxlength="700" rows="3" placeholder="Alguma informação importante para o atendimento?">${esc(state.notes)}</textarea></label></div>`;
   return;
  }
  const intro=state.reschedule?'Entre na conta que realizou o agendamento para solicitar a mudança.':'Para acompanhar seus horários, ao agendar você cria uma conta segura.';
  content.innerHTML=stepHeader('Seus dados de contato',intro)+
   `<div class="pill-tabs"><button type="button" data-customer-mode="register" class="${state.mode==='register'?'active':''}" ${state.reschedule?'hidden':''}>Primeira visita</button><button type="button" data-customer-mode="login" class="${state.mode==='login'?'active':''}">Já sou cliente</button></div>`+
   (state.mode==='login'||state.reschedule?`<form id="inlineLogin" class="form-stack"><label>Telefone com DDD<input name="phone" type="tel" required autocomplete="tel-national"></label><label>Senha<input name="password" type="password" required autocomplete="current-password"></label><button class="btn btn-primary full" type="submit">Entrar na minha conta <i data-lucide="log-in"></i></button></form>`:
   `<form id="bookingRegistration" class="form-stack"><label>Nome completo<input name="name" required maxlength="150" autocomplete="name" value="${esc(state.customer?.name||'')}"></label><label>Telefone / WhatsApp com DDD<input name="phone" type="tel" required autocomplete="tel-national" value="${esc(state.customer?.phone||'')}"></label><label>E-mail <span class="optional">opcional</span><input name="email" type="email" autocomplete="email" value="${esc(state.customer?.email||'')}"></label><label>Crie uma senha (mínimo 10 caracteres)<input name="password" type="password" minlength="10" maxlength="128" required autocomplete="new-password" value="${esc(state.customer?.password||'')}"></label><label>Observações <span class="optional">opcional</span><textarea rows="3" name="notes" maxlength="700" placeholder="Nos conte se precisar de algo especial.">${esc(state.notes)}</textarea></label><label class="checkbox-line"><input type="checkbox" name="consent" required ${state.customer?.consent?'checked':''}> Li e aceito a <a href="${window.ESSENZA.base}/privacidade" target="_blank">Política de Privacidade</a>.</label></form>`);
  $('#bookingNext').disabled=(state.mode==='login'||state.reschedule);
 }
 function renderConfirmation(){
  const s=service(),p=state.staffId?professional()?.name:'Qualquer profissional disponível';
  const rows=[['Serviço',s?.name],['Profissional',p],['Data',niceDate(state.date)],['Horário',state.time],['Duração',`${s?.duration_minutes||0} minutos`],['Valor',money(s?.price)],['Cliente',state.client?.name||state.customer?.name],['WhatsApp',state.client?.phone||state.customer?.phone]];
  content.innerHTML=stepHeader('Um último detalhe...','Confira com carinho os dados abaixo antes de reservar seu horário.')+
   `<div class="booking-ticket"><div class="ticket-grid">${rows.map(([label,value])=>`<div><span>${esc(label)}</span><strong>${esc(value)}</strong></div>`).join('')}</div></div><p class="subtle">Ao confirmar, o horário será reservado conforme disponibilidade atual. Caso outra pessoa agende primeiro, você poderá escolher um novo horário. ${state.reschedule?'Esta ação atualizará seu agendamento existente.':''}</p>`;
 }
 function goNext(){
  if(state.step===1&&!service())return showError('Selecione um serviço.');
  if(state.step===2&&!choices().length)return showError('Não existem profissionais disponíveis para este serviço.');
  if(state.step===3&&!(state.date&&state.time))return showError('Escolha uma data e um horário.');
  if(state.step===4){
    if(!state.client){
      if(state.mode==='login'||state.reschedule)return showError('Entre na sua conta para continuar.');
      const form=$('#bookingRegistration');if(!form?.reportValidity())return;
      state.customer=inputData(form);state.customer.consent=$('[name="consent"]',form).checked;
      state.notes=state.customer.notes||'';
    }else state.notes=$('#bookingNotes')?.value||'';
  }
  if(state.step===5)return submitAppointment();
  state.step++;render();window.scrollTo({top:Math.max(0,document.querySelector('.booking-section').offsetTop-90),behavior:'smooth'});
 }
 function showError(message){error.textContent=message;toast(message);}
 async function submitAppointment(){
  const btn=$('#bookingNext');btn.disabled=true;btn.textContent='Confirmando...';error.textContent='';
  try{
   const data={service_id:state.serviceId,staff_id:state.staffId,date:state.date,time:state.time,notes:state.notes};
   if(!state.client&&!state.reschedule)data.customer=state.customer;
   if(state.reschedule){await api(`/api/client/bookings/${state.reschedule}/reschedule`,{method:'POST',data});location.href=window.ESSENZA.base+'/minha-conta';return;}
   const result=await api('/api/bookings',{method:'POST',data});location.href=result.redirect;
  }catch(err){showError(err.message);btn.disabled=false;btn.textContent='Confirmar agendamento';renderIcons();}
 }
 content.addEventListener('click',async ev=>{
  const btn=ev.target.closest('button');if(!btn)return;
  if(btn.hasAttribute('data-category')){state.category=Number(btn.dataset.category);if(state.category&&service()&&Number(service().category_id)!==state.category&&!state.reschedule){state.serviceId=0;state.staffId=0;state.date='';state.time='';}render();return;}
  if(btn.hasAttribute('data-service')){
   if(state.reschedule&&state.serviceId!==Number(btn.dataset.service))return;
   state.serviceId=Number(btn.dataset.service);const desired=state.staffId||Number(initial.professional)||0;state.staffId=catalog.staff_services.some(x=>Number(x.staff_id)===desired&&Number(x.service_id)===state.serviceId)?desired:0;state.date='';state.time='';render();return;
  }
  if(btn.hasAttribute('data-staff')){state.staffId=Number(btn.dataset.staff);state.date='';state.time='';render();return;}
  if(btn.hasAttribute('data-month')){
   const [year,mon]=state.month.split('-').map(Number);const v=new Date(year,mon-1+Number(btn.dataset.month),1);state.month=dateLocal(v.getFullYear(),v.getMonth()+1,1).slice(0,7);state.date='';state.time='';state.slots=[];render();return;
  }
  if(btn.hasAttribute('data-day')){await loadSlots(btn.dataset.day);return;}
  if(btn.hasAttribute('data-time')){state.time=btn.dataset.time;drawSlots();summary();return;}
  if(btn.hasAttribute('data-customer-mode')){state.mode=btn.dataset.customerMode;renderCustomer();renderIcons();return;}
 });
 content.addEventListener('submit',async ev=>{
  if(ev.target.id!=='inlineLogin')return;ev.preventDefault();const form=ev.target;if(!form.reportValidity())return;
  const btn=form.querySelector('button');btn.disabled=true;
  try{const result=await api('/api/client/login',{method:'POST',data:inputData(form)});state.client=result.client;state.mode='login';render();toast('Bem-vinda de volta!');}
  catch(err){showError(err.message);}finally{btn.disabled=false;}
 });
 $('#bookingBack').addEventListener('click',()=>{if(state.step>1){state.step--;render();}});
 $('#bookingNext').addEventListener('click',goNext);
 try{
  catalog=await api('/api/catalog');state.client=catalog.client;
  if(state.reschedule)state.mode='login';
  if(state.serviceId&&!service())state.serviceId=0;
  if(state.staffId&&!catalog.staff.some(s=>Number(s.id)===state.staffId))state.staffId=0;
  // Business-local month from server settings timezone rather than visitor timezone.
  const parts=new Intl.DateTimeFormat('en-US',{timeZone:catalog.settings.timezone||'America/Sao_Paulo',year:'numeric',month:'2-digit'}).formatToParts(new Date());
  const year=parts.find(x=>x.type==='year').value,month=parts.find(x=>x.type==='month').value;
  state.month=`${year}-${month}`;
  render();
 }catch(err){content.innerHTML=`<p class="form-error">${esc(err.message)}</p>`;toast(err.message);}
});
