<?php
declare(strict_types=1);

function onlyPositive(mixed $v,string $label): int {
    $n=filter_var($v,FILTER_VALIDATE_INT);
    if ($n===false || $n<1) fail("Informe $label válido.");
    return $n;
}
function adminListAppointments(): array {
    $from=validDate($_GET['from']??date('Y-m-d'));
    $to=validDate($_GET['to']??$from);
    if ($to<$from || (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days>95) fail('O período máximo é de 95 dias.');
    $clauses=['a.scheduled_date BETWEEN ? AND ?'];$params=[$from,$to];
    if(!empty($_GET['staff_id'])){$clauses[]='a.staff_id=?';$params[]=(int)$_GET['staff_id'];}
    if(!empty($_GET['service_id'])){$clauses[]='a.service_id=?';$params[]=(int)$_GET['service_id'];}
    if(!empty($_GET['status'])){
        if(!in_array($_GET['status'],['pending','confirmed','completed','cancelled','no_show'],true))fail('Status inválido.');
        $clauses[]='a.status=?';$params[]=$_GET['status'];
    }
    return sql('SELECT a.id,a.confirmation_code,a.scheduled_date,a.starts,a.ends,a.status,a.price_snapshot,a.notes,
        a.client_id,a.staff_id,a.service_id,c.name client_name,c.phone client_phone,s.name service_name,st.name staff_name
        FROM appointments a JOIN clients c ON c.id=a.client_id JOIN services s ON s.id=a.service_id JOIN staff st ON st.id=a.staff_id
        WHERE '.implode(' AND ',$clauses).' ORDER BY a.scheduled_date,a.starts,st.name',$params)->fetchAll();
}
function adminApiHandle(string $path,string $method): never {
    $admin=requireAdmin();
    $canEdit=in_array($admin['role'],['owner','manager'],true);
    $editing=function()use($canEdit){if(!$canEdit)fail('Apenas gestão e proprietários podem editar estes registros.',403);};

    if ($path==='/api/admin/dashboard' && $method==='GET') {
        $today=date('Y-m-d');
        $metrics=sql("SELECT
        (SELECT COUNT(*) FROM appointments WHERE scheduled_date=? AND status IN ('pending','confirmed','completed')) appointments_today,
        (SELECT COUNT(*) FROM appointments WHERE scheduled_date>=? AND status='pending') pending,
        (SELECT COUNT(*) FROM clients WHERE active=1) customers,
        (SELECT COALESCE(SUM(price_snapshot),0) FROM appointments WHERE status='completed') revenue",[$today,$today])->fetch();
        $upcoming=sql("SELECT a.id,a.scheduled_date,a.starts,a.status,c.name client_name,s.name service_name,st.name staff_name
            FROM appointments a JOIN clients c ON c.id=a.client_id JOIN services s ON s.id=a.service_id JOIN staff st ON st.id=a.staff_id
            WHERE a.status IN ('pending','confirmed') AND TIMESTAMP(a.scheduled_date,a.starts)>=? ORDER BY a.scheduled_date,a.starts LIMIT 8",[date('Y-m-d H:i:s')])->fetchAll();
        $popular=sql("SELECT s.name,COUNT(*) total FROM appointments a JOIN services s ON s.id=a.service_id WHERE a.status<>'cancelled' GROUP BY a.service_id,s.name ORDER BY total DESC LIMIT 6")->fetchAll();
        jsonResponse(['metrics'=>$metrics,'upcoming'=>$upcoming,'popular'=>$popular]);
    }
    if($path==='/api/admin/appointments' && $method==='GET')jsonResponse(['appointments'=>adminListAppointments()]);
    if($path==='/api/admin/appointments' && $method==='POST') {
        $d=body();$clientId=onlyPositive($d['client_id']??null,'cliente');
        jsonResponse(['ok'=>true,'booking'=>makeAppointment($d,$clientId,true)],201);
    }
    if(preg_match('#^/api/admin/appointments/(\d+)/reschedule$#',$path,$m) && $method==='POST') {
        rescheduleAppointment((int)$m[1],body(),null,true);
        jsonResponse(['ok'=>true]);
    }
    if(preg_match('#^/api/admin/appointments/(\d+)$#',$path,$m) && $method==='PATCH') {
        $d=body();$status=$d['status']??'';
        if(!in_array($status,['pending','confirmed','completed','cancelled','no_show'],true))fail('Status inválido.');
        $pdo=db();$pdo->beginTransaction();
        try {
            $row=sql('SELECT id,status FROM appointments WHERE id=? FOR UPDATE',[(int)$m[1]])->fetch();
            if(!$row)bookingError('Agendamento não encontrado.');
            if (in_array($row['status'],['cancelled','completed','no_show'],true) && in_array($status,['pending','confirmed'],true)) bookingError('Não é possível reativar este atendimento. Crie um novo agendamento.');
            sql("UPDATE appointments SET status=?,cancelled_at=IF(?='cancelled',NOW(),NULL) WHERE id=?",[$status,$status,(int)$m[1]]);
            eventLog((int)$m[1],'admin',(int)$admin['id'],'status_updated',['from'=>$row['status'],'to'=>$status]);
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        jsonResponse(['ok'=>true]);
    }
    if ($path==='/api/admin/services' && $method==='GET') {
        jsonResponse(['services'=>sql('SELECT s.*,c.name category FROM services s JOIN categories c ON c.id=s.category_id ORDER BY c.sort_order,s.name')->fetchAll(),
            'categories'=>sql('SELECT * FROM categories ORDER BY sort_order')->fetchAll()]);
    }
    if (preg_match('#^/api/admin/services(?:/(\d+))?$#',$path,$m) && in_array($method,['POST','PUT'],true)) {
        $editing();$d=body();$id=(int)($m[1]??0);
        if ($method==='PUT' && $id<1) fail('Serviço não encontrado.');
        $category=onlyPositive($d['category_id']??null,'categoria');
        if(!sql('SELECT 1 FROM categories WHERE id=?',[$category])->fetch())fail('Categoria inválida.');
        $name=requiredString($d['name']??null,'nome',130);
        $desc=optionalString($d['description']??'',255);
        $price=filter_var($d['price']??null,FILTER_VALIDATE_FLOAT);
        $duration=filter_var($d['duration_minutes']??null,FILTER_VALIDATE_INT);
        if($price===false || $price<0 || $price>99999 || $duration===false || $duration<15 || $duration>480 || $duration%15!==0)fail('Preço ou duração inválidos (duração de 15 a 480 min, múltipla de 15).');
        $image=assertConfigImage((string)($d['image']??'hair.webp'));
        $active=!empty($d['active'])?1:0;
        if($id){
            sql('UPDATE services SET category_id=?,name=?,description=?,price=?,duration_minutes=?,image=?,active=? WHERE id=?',[$category,$name,$desc,$price,$duration,$image,$active,$id]);
        }else{
            sql('INSERT INTO services(category_id,name,description,price,duration_minutes,image,active) VALUES (?,?,?,?,?,?,?)',[$category,$name,$desc,$price,$duration,$image,$active]);
            $id=(int)db()->lastInsertId();
        }
        jsonResponse(['ok'=>true,'id'=>$id]);
    }
    if($path==='/api/admin/staff' && $method==='GET') {
        $staff=sql('SELECT * FROM staff ORDER BY id')->fetchAll();
        foreach($staff as &$person) {
            $person['service_ids']=array_map('intval',sql('SELECT service_id FROM staff_services WHERE staff_id=?',[$person['id']])->fetchAll(PDO::FETCH_COLUMN));
            $person['hours']=sql('SELECT weekday,starts,ends,break_starts,break_ends,enabled FROM staff_hours WHERE staff_id=? ORDER BY weekday',[$person['id']])->fetchAll();
            $person['days_off']=sql('SELECT off_date,reason FROM staff_days_off WHERE staff_id=? AND off_date>=CURDATE() ORDER BY off_date',[$person['id']])->fetchAll();
        }
        jsonResponse(['staff'=>$staff]);
    }
    if(preg_match('#^/api/admin/staff(?:/(\d+))?$#',$path,$m) && in_array($method,['POST','PUT'],true)) {
        $editing();$d=body();$id=(int)($m[1]??0);
        if($method==='PUT'&&!$id)fail('Profissional não encontrado.');
        $name=requiredString($d['name']??null,'nome',130);
        $specialty=optionalString($d['specialty']??'',150);
        $bio=optionalString($d['bio']??'',350);
        $photo=assertConfigImage((string)($d['photo']??'staff-1.webp'));
        $active=!empty($d['active'])?1:0;
        $serviceIds=array_values(array_unique(array_map('intval',(array)($d['service_ids']??[]))));
        $hours=(array)($d['hours']??[]);
        $pdo=db();$pdo->beginTransaction();
        try {
            if($id) sql('UPDATE staff SET name=?,specialty=?,bio=?,photo=?,active=? WHERE id=?',[$name,$specialty,$bio,$photo,$active,$id]);
            else{
                sql('INSERT INTO staff(name,specialty,bio,photo,active) VALUES (?,?,?,?,?)',[$name,$specialty,$bio,$photo,$active]);
                $id=(int)$pdo->lastInsertId();
            }
            sql('DELETE FROM staff_services WHERE staff_id=?',[$id]);
            foreach($serviceIds as $sid) if($sid>0)sql('INSERT INTO staff_services(staff_id,service_id) VALUES (?,?)',[$id,$sid]);
            if(array_key_exists('hours',$d)) {
                sql('DELETE FROM staff_hours WHERE staff_id=?',[$id]);
                foreach($hours as $h) {
                    $wd=(int)($h['weekday']??-1);
                    if($wd<0||$wd>6) bookingError('Dia da semana inválido.');
                    if(empty($h['enabled']))continue;
                    $start=validClock($h['starts']??null);$end=validClock($h['ends']??null);
                    if($start>=$end)bookingError('Jornada precisa terminar depois do início.');
                    $bs=empty($h['break_starts'])?null:validClock($h['break_starts']);
                    $be=empty($h['break_ends'])?null:validClock($h['break_ends']);
                    if (($bs===null)!==($be===null) || ($bs!==null && ($bs>=$be||$bs<$start||$be>$end)))bookingError('Pausa inválida.');
                    sql('INSERT INTO staff_hours(staff_id,weekday,starts,ends,break_starts,break_ends,enabled) VALUES (?,?,?,?,?,?,1)',[$id,$wd,$start,$end,$bs,$be]);
                }
            }
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        jsonResponse(['ok'=>true,'id'=>$id]);
    }
    if ($path==='/api/admin/clients' && $method==='GET') {
        $search=trim((string)($_GET['search']??''));
        if(mb_strlen($search)>100)fail('Busca muito longa.');
        $param='%'.$search.'%';
        $clients=sql('SELECT c.id,c.name,c.phone,c.email,c.created_at,COUNT(a.id) total_appointments FROM clients c
            LEFT JOIN appointments a ON a.client_id=c.id WHERE c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?
            GROUP BY c.id,c.name,c.phone,c.email,c.created_at ORDER BY c.name LIMIT 150',[$param,$param,$param])->fetchAll();
        jsonResponse(['clients'=>$clients]);
    }
    if($path==='/api/admin/clients' && $method==='POST') {
        $d=body();$name=requiredString($d['name']??null,'nome',150);$phone=normalizePhone($d['phone']??null);
        $email=validEmail($d['email']??null);$pass=passwordOk($d['password']??null);
        if(empty($d['consent']))fail('Confirme que o cliente consentiu com o cadastro.');
        if(sql('SELECT 1 FROM clients WHERE phone=?',[$phone])->fetch())fail('Telefone já cadastrado.',409);
        sql('INSERT INTO clients(name,phone,email,password_hash,consent_at) VALUES (?,?,?,?,NOW())',[$name,$phone,$email,passHash($pass)]);
        jsonResponse(['ok'=>true,'id'=>(int)db()->lastInsertId()],201);
    }
    if(preg_match('#^/api/admin/clients/(\d+)$#',$path,$m) && $method==='GET') {
        $row=sql('SELECT id,name,phone,email,created_at FROM clients WHERE id=?',[(int)$m[1]])->fetch();
        if(!$row)fail('Cliente não encontrado.',404);
        $history=sql('SELECT a.scheduled_date,a.starts,a.status,a.price_snapshot,s.name service_name,st.name staff_name FROM appointments a JOIN services s ON s.id=a.service_id JOIN staff st ON st.id=a.staff_id WHERE a.client_id=? ORDER BY a.scheduled_date DESC LIMIT 50',[(int)$m[1]])->fetchAll();
        $notes=sql('SELECT n.id,n.note,n.created_at,a.name author FROM client_notes n JOIN admins a ON a.id=n.admin_id WHERE n.client_id=? ORDER BY n.created_at DESC LIMIT 100',[(int)$m[1]])->fetchAll();
        jsonResponse(['client'=>$row,'history'=>$history,'notes'=>$notes]);
    }
    if(preg_match('#^/api/admin/clients/(\d+)/notes$#',$path,$m) && $method==='POST') {
        $d=body();
        if(empty($d['authorized']))fail('Confirme que a observação é pertinente e autorizada.');
        if(!sql('SELECT 1 FROM clients WHERE id=?',[(int)$m[1]])->fetch())fail('Cliente não encontrado.',404);
        sql('INSERT INTO client_notes(client_id,admin_id,note,client_authorization_confirmed) VALUES (?,?,?,1)',[(int)$m[1],(int)$admin['id'],requiredString($d['note']??null,'observação',700)]);
        jsonResponse(['ok'=>true]);
    }
    if(preg_match('#^/api/admin/clients/(\d+)/notes/(\d+)$#',$path,$m) && $method==='DELETE') {
        $editing();
        sql('DELETE FROM client_notes WHERE client_id=? AND id=?',[(int)$m[1],(int)$m[2]]);
        jsonResponse(['ok'=>true]);
    }
    if($path==='/api/admin/settings' && $method==='GET') {
        jsonResponse(['settings'=>salon(),
            'hours'=>sql('SELECT * FROM salon_hours ORDER BY weekday')->fetchAll(),
            'closures'=>sql('SELECT * FROM salon_closures WHERE closed_date>=CURDATE() ORDER BY closed_date LIMIT 100')->fetchAll(),
            'gallery'=>sql('SELECT * FROM gallery ORDER BY sort_order,id')->fetchAll(),
        ]);
    }
    if($path==='/api/admin/settings' && $method==='PUT') {
        $editing();$d=body();
        $keys=['salon_name','subtitle','hero_title','hero_description','about_text','address','city','phone','whatsapp','email','instagram','logo','brand_color','timezone'];
        $values=[];$changes=[];
        foreach($keys as $key) if(array_key_exists($key,$d)) {
            $v=optionalString($d[$key],in_array($key,['hero_description','about_text'],true)?3000:255);
            if($key==='salon_name')$v=requiredString($v,'nome do salão',120);
            if($key==='email')$v=validEmail($v)??'';
            if($key==='brand_color'&&!preg_match('/^#[A-Fa-f0-9]{6}$/',$v))fail('Cor inválida.');
            if($key==='timezone'&&!in_array($v,timezone_identifiers_list(),true))fail('Fuso horário inválido.');
            if($key==='logo'&&$v!=='')$v=assertConfigImage($v);
            if($key==='instagram'&&$v!==''&&!preg_match('#^https://#',$v))fail('Instagram deve usar HTTPS.');
            if($key==='whatsapp'&&$v!==''&&!preg_match('/^\d{10,15}$/',$v))fail('WhatsApp deve conter DDI e somente números.');
            $changes[]="$key=?";$values[]=$v;
        }
        foreach(['booking_lead_minutes'=>[0,1440],'booking_horizon_days'=>[1,365],'cancellation_hours'=>[0,168]] as $key=>[$min,$max]) if(array_key_exists($key,$d)){
            $v=filter_var($d[$key],FILTER_VALIDATE_INT);
            if($v===false||$v<$min||$v>$max)fail("Valor inválido para $key.");
            $changes[]="$key=?";$values[]=$v;
        }
        if($changes){$values[]=1;sql('UPDATE settings SET '.implode(',',$changes).' WHERE id=?',$values);}
        if(isset($d['hours'])&&is_array($d['hours'])){
            $pdo=db();$pdo->beginTransaction();
            try {
                foreach($d['hours'] as $h){
                    $day=(int)($h['weekday']??-1);if($day<0||$day>6)bookingError('Dia inválido.');
                    $closed=!empty($h['closed'])?1:0;
                    $opens=$closed?null:validClock($h['opens']??null);$closes=$closed?null:validClock($h['closes']??null);
                    if(!$closed&&$opens>=$closes)bookingError('Horário de funcionamento inválido.');
                    sql('INSERT INTO salon_hours(weekday,opens,closes,closed) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE opens=VALUES(opens),closes=VALUES(closes),closed=VALUES(closed)',[$day,$opens,$closes,$closed]);
                }
                $pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        }
        jsonResponse(['ok'=>true]);
    }
    if($path==='/api/admin/blocks' && $method==='GET'){
        $from=validDate($_GET['from']??date('Y-m-d'));
        jsonResponse(['blocks'=>sql('SELECT b.*,s.name staff_name FROM schedule_blocks b JOIN staff s ON s.id=b.staff_id WHERE b.block_date>=? ORDER BY b.block_date,b.starts LIMIT 250',[$from])->fetchAll()]);
    }
    if($path==='/api/admin/blocks' && $method==='POST'){
        $d=body();$staff=onlyPositive($d['staff_id']??null,'profissional');
        $date=validDate($d['date']??null);$start=validClock($d['starts']??null);$end=validClock($d['ends']??null);
        if($start>=$end)fail('O fim do bloqueio deve ser depois do início.');
        $reason=optionalString($d['reason']??'',180);
        $pdo=db();$pdo->beginTransaction();
        try {
            lockWorkday($staff,$date);
            $conflict=sql("SELECT id FROM appointments WHERE staff_id=? AND scheduled_date=? AND status IN ('pending','confirmed') AND starts<? AND ends>? LIMIT 1 FOR UPDATE",[$staff,$date,$end,$start])->fetch();
            if($conflict)bookingError('Existem atendimentos nesse período. Reagende-os antes de bloquear.');
            sql('INSERT INTO schedule_blocks(staff_id,block_date,starts,ends,reason) VALUES (?,?,?,?,?)',[$staff,$date,$start,$end,$reason]);
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        jsonResponse(['ok'=>true]);
    }
    if(preg_match('#^/api/admin/blocks/(\d+)$#',$path,$m)&&$method==='DELETE'){
        sql('DELETE FROM schedule_blocks WHERE id=?',[(int)$m[1]]);
        jsonResponse(['ok'=>true]);
    }
    if($path==='/api/admin/closures'&&$method==='POST'){
        $editing();$d=body();$date=validDate($d['date']??null);
        $pdo=db();$pdo->beginTransaction();
        try {
            $people=sql('SELECT id FROM staff WHERE active=1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
            foreach($people as $staffId)lockWorkday((int)$staffId,$date);
            if(sql("SELECT id FROM appointments WHERE scheduled_date=? AND status IN ('pending','confirmed') LIMIT 1 FOR UPDATE",[$date])->fetch())bookingError('Há agendamentos ativos neste dia. Reagende-os antes de fechar o salão.');
            sql('INSERT INTO salon_closures(closed_date,reason) VALUES (?,?) ON DUPLICATE KEY UPDATE reason=VALUES(reason)',[$date,optionalString($d['reason']??'Fechado',160)]);
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        jsonResponse(['ok'=>true]);
    }
    if(preg_match('#^/api/admin/closures/(\d{4}-\d{2}-\d{2})$#',$path,$m)&&$method==='DELETE'){
        $editing();sql('DELETE FROM salon_closures WHERE closed_date=?',[validDate($m[1])]);jsonResponse(['ok'=>true]);
    }
    if($path==='/api/admin/staff-days-off'&&$method==='POST'){
        $editing();$d=body();$staff=onlyPositive($d['staff_id']??null,'profissional');$date=validDate($d['date']??null);
        $pdo=db();$pdo->beginTransaction();
        try{
            lockWorkday($staff,$date);
            if(sql("SELECT id FROM appointments WHERE staff_id=? AND scheduled_date=? AND status IN ('pending','confirmed') LIMIT 1 FOR UPDATE",[$staff,$date])->fetch())bookingError('Profissional tem atendimentos neste dia. Reagende antes de inserir folga.');
            sql('INSERT INTO staff_days_off(staff_id,off_date,reason) VALUES (?,?,?) ON DUPLICATE KEY UPDATE reason=VALUES(reason)',[$staff,$date,optionalString($d['reason']??'',160)]);
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        jsonResponse(['ok'=>true]);
    }
    if(preg_match('#^/api/admin/staff-days-off/(\d+)/(\d{4}-\d{2}-\d{2})$#',$path,$m)&&$method==='DELETE'){
        $editing();sql('DELETE FROM staff_days_off WHERE staff_id=? AND off_date=?',[(int)$m[1],validDate($m[2])]);jsonResponse(['ok'=>true]);
    }
    if($path==='/api/admin/gallery'&&$method==='POST'){
        $editing();$d=body();$image=assertConfigImage(requiredString($d['image']??null,'imagem',255));
        sql('INSERT INTO gallery(image,caption,sort_order,active) VALUES (?,?,?,1)',[$image,optionalString($d['caption']??'',150),(int)($d['sort_order']??0)]);
        jsonResponse(['ok'=>true]);
    }
    if(preg_match('#^/api/admin/gallery/(\d+)$#',$path,$m)&&$method==='DELETE'){
        $editing();sql('DELETE FROM gallery WHERE id=?',[(int)$m[1]]);jsonResponse(['ok'=>true]);
    }
    fail('Rota administrativa não encontrada.',404);
}
