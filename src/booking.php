<?php
declare(strict_types=1);

class BookingError extends RuntimeException {}
function bookingError(string $message): never { throw new BookingError($message); }
function minuteOf(string $clock): int { [$h,$m]=array_map('intval',explode(':',substr($clock,0,5))); return $h*60+$m; }
function clockOf(int $minutes): string { return sprintf('%02d:%02d',intdiv($minutes,60),$minutes%60); }
function dateAllowed(string $date): bool {
    $s=salon();
    $start=new DateTimeImmutable('today');
    $last=$start->modify('+' . (int)($s['booking_horizon_days'] ?? 90) . ' days');
    $d=new DateTimeImmutable($date . ' 00:00:00');
    return $d >= $start && $d <= $last;
}
function serviceById(int $id): array {
    $s=sql('SELECT s.*,c.name category FROM services s JOIN categories c ON c.id=s.category_id WHERE s.id=? AND s.active=1',[$id])->fetch();
    if (!$s) bookingError('Serviço indisponível.');
    return $s;
}
function professionalsFor(int $serviceId): array {
    return sql('SELECT st.id,st.name,st.specialty,st.photo FROM staff st JOIN staff_services ss ON ss.staff_id=st.id WHERE ss.service_id=? AND st.active=1 ORDER BY st.id',[$serviceId])->fetchAll();
}
function lockWorkday(int $staffId,string $date): void {
    sql('INSERT IGNORE INTO booking_day_locks(staff_id,work_date) VALUES (?,?)',[$staffId,$date]);
    sql('SELECT staff_id FROM booking_day_locks WHERE staff_id=? AND work_date=? FOR UPDATE',[$staffId,$date]);
}
function intersects(int $start,int $end,int $otherStart,int $otherEnd): bool {
    return $start < $otherEnd && $end > $otherStart;
}
/** Recalculates real slots on the server. When used after lockWorkday, $locking=true obtains fresh InnoDB reads. */
function slotsForStaff(array $service,int $staffId,string $date,bool $locking=false,?int $ignoreAppointmentId=null): array {
    if (!dateAllowed($date)) return [];
    $weekday=(int)(new DateTimeImmutable($date))->format('w');
    $hours=sql('SELECT sh.opens,sh.closes,sh.closed,wh.starts,wh.ends,wh.break_starts,wh.break_ends,wh.enabled
        FROM salon_hours sh LEFT JOIN staff_hours wh ON wh.weekday=sh.weekday AND wh.staff_id=? WHERE sh.weekday=?' . ($locking ? ' FOR UPDATE' : ''),[$staffId,$weekday])->fetch();
    if (!$hours || $hours['closed'] || !$hours['enabled'] || !$hours['starts']) return [];
    if (sql('SELECT 1 FROM salon_closures WHERE closed_date=?' . ($locking ? ' FOR UPDATE' : ''),[$date])->fetchColumn()) return [];
    if (sql('SELECT 1 FROM staff_days_off WHERE staff_id=? AND off_date=?' . ($locking ? ' FOR UPDATE' : ''),[$staffId,$date])->fetchColumn()) return [];
    $start=max(minuteOf($hours['opens']),minuteOf($hours['starts']));
    $end=min(minuteOf($hours['closes']),minuteOf($hours['ends']));
    $duration=(int)$service['duration_minutes'];
    if ($end-$start < $duration) return [];
    $blocks=[];
    if ($hours['break_starts'] && $hours['break_ends']) $blocks[]=[minuteOf($hours['break_starts']),minuteOf($hours['break_ends'])];
    $apts=sql("SELECT id,starts,ends FROM appointments WHERE staff_id=? AND scheduled_date=? AND status IN ('pending','confirmed')" . ($locking ? ' FOR UPDATE' : ''),[$staffId,$date])->fetchAll();
    foreach ($apts as $apt) if ((int)$apt['id'] !== $ignoreAppointmentId) $blocks[]=[minuteOf($apt['starts']),minuteOf($apt['ends'])];
    $manual=sql('SELECT starts,ends FROM schedule_blocks WHERE staff_id=? AND block_date=?' . ($locking ? ' FOR UPDATE' : ''),[$staffId,$date])->fetchAll();
    foreach ($manual as $b) $blocks[]=[minuteOf($b['starts']),minuteOf($b['ends'])];
    $minTime=(new DateTimeImmutable('now'))->modify('+' . (int)(salon()['booking_lead_minutes'] ?? 60) . ' minutes');
    $slots=[];
    for ($m=(int)(ceil($start/30)*30);$m+$duration<=$end;$m+=30) {
        $at=new DateTimeImmutable($date . ' ' . clockOf($m));
        if ($at < $minTime) continue;
        $free=true;
        foreach($blocks as [$a,$b]) if (intersects($m,$m+$duration,$a,$b)) { $free=false;break; }
        if ($free) $slots[]=clockOf($m);
    }
    return $slots;
}
function availableSlots(int $serviceId,int $staffId,string $date): array {
    $service=serviceById($serviceId);
    $people=professionalsFor($serviceId);
    if ($staffId) $people=array_values(array_filter($people,fn($p)=>(int)$p['id']===$staffId));
    $aggregated=[];
    foreach ($people as $person) {
        foreach (slotsForStaff($service,(int)$person['id'],$date) as $hour) {
            $aggregated[$hour] ??= [];
            $aggregated[$hour][]=(int)$person['id'];
        }
    }
    ksort($aggregated);
    return array_map(fn($hour,$ids)=>['time'=>$hour,'professional_ids'=>$ids],array_keys($aggregated),array_values($aggregated));
}
function availableDays(int $serviceId,int $staffId,string $month): array {
    if (!preg_match('/^\d{4}-\d{2}$/',$month)) bookingError('Mês inválido.');
    $first=DateTimeImmutable::createFromFormat('!Y-m',$month);
    if (!$first || $first->format('Y-m')!==$month) bookingError('Mês inválido.');
    $service=serviceById($serviceId);
    $people=professionalsFor($serviceId);
    if ($staffId) $people=array_filter($people,fn($p)=>(int)$p['id']===$staffId);
    $days=[];
    for($day=$first;$day->format('Y-m')===$month;$day=$day->modify('+1 day')) {
        $date=$day->format('Y-m-d');
        if (!dateAllowed($date)) continue;
        foreach($people as $p) {
            if (slotsForStaff($service,(int)$p['id'],$date)!==[]) { $days[]=$date;break; }
        }
    }
    return $days;
}
function selectStaffForSlot(array $service,int $requestedStaff,string $date,string $hour,?int $ignoreId=null): int {
    $people=professionalsFor((int)$service['id']);
    if ($requestedStaff) $people=array_values(array_filter($people,fn($p)=>(int)$p['id']===$requestedStaff));
    if (!$people) bookingError('O profissional não realiza esse serviço.');
    // Consistent ascending lock order avoids opposite-order deadlocks for "any professional".
    foreach($people as $p) lockWorkday((int)$p['id'],$date);
    foreach($people as $p) {
        if (in_array($hour,slotsForStaff($service,(int)$p['id'],$date,true,$ignoreId),true)) return (int)$p['id'];
    }
    bookingError('Este horário acabou de ser ocupado. Selecione outra opção.');
}
function makeAppointment(array $data,?int $clientId=null,bool $admin=false): array {
    $serviceId=(int)($data['service_id'] ?? 0);
    $requestedStaff=max(0,(int)($data['staff_id'] ?? 0));
    $date=validDate($data['date'] ?? null);
    $hour=validClock($data['time'] ?? null);
    $notes=optionalString($data['notes'] ?? '',700);
    $service=serviceById($serviceId);
    if (!dateAllowed($date)) bookingError('Data fora do período de agendamento.');
    $registration=null;
    if (!$clientId && !$admin) {
        $customer=(array)($data['customer'] ?? []);
        $registration=[
            'name'=>requiredString($customer['name'] ?? null,'nome completo',150),
            'phone'=>normalizePhone($customer['phone'] ?? null),
            'email'=>validEmail($customer['email'] ?? null),
            'password'=>passwordOk($customer['password'] ?? null),
        ];
        if (empty($customer['consent'])) bookingError('É necessário aceitar a política de privacidade.');
    }
    $pdo=db();
    $pdo->beginTransaction();
    try {
        if ($registration) {
            if (sql('SELECT id FROM clients WHERE phone=?',[$registration['phone']])->fetch()) bookingError('Este telefone já possui conta. Entre antes de agendar.');
            sql('INSERT INTO clients(name,phone,email,password_hash,consent_at) VALUES (?,?,?,?,NOW())',[
                $registration['name'],$registration['phone'],$registration['email'],passHash($registration['password'])]);
            $clientId=(int)$pdo->lastInsertId();
        }
        if (!$clientId) bookingError('Selecione um cliente cadastrado.');
        $client=sql('SELECT id,name,phone,email FROM clients WHERE id=? AND active=1',[$clientId])->fetch();
        if (!$client) bookingError('Cliente não encontrado.');
        $staffId=selectStaffForSlot($service,$requestedStaff,$date,$hour);
        $code='ESZ-' . strtoupper(bin2hex(random_bytes(10)));
        $end=clockOf(minuteOf($hour)+(int)$service['duration_minutes']);
        sql('INSERT INTO appointments(confirmation_code,client_id,staff_id,service_id,scheduled_date,starts,ends,price_snapshot,duration_minutes,client_name_snapshot,client_phone_snapshot,client_email_snapshot,notes,status)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[
            $code,$clientId,$staffId,$serviceId,$date,$hour,$end,$service['price'],$service['duration_minutes'],$client['name'],$client['phone'],$client['email'],$notes,$admin ? 'confirmed' : 'confirmed'
        ]);
        $id=(int)$pdo->lastInsertId();
        eventLog($id,$admin?'admin':'client',$admin?(int)authAdmin()['id']:$clientId,'created',['date'=>$date,'time'=>$hour,'staff_id'=>$staffId]);
        $pdo->commit();
        if ($registration) signIn('client',$clientId);
        return ['id'=>$id,'code'=>$code,'date'=>$date,'time'=>$hour,'staff_id'=>$staffId,'created_account'=>(bool)$registration,'csrf'=>csrfToken()];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof PDOException && ($e->errorInfo[1] ?? null) === 1062) bookingError('Já existe uma conta com este telefone. Entre na sua conta.');
        throw $e;
    }
}
function appointmentForClient(int $id,int $clientId): array {
    $row=sql('SELECT a.*,sv.name service_name,st.name staff_name FROM appointments a
        JOIN services sv ON sv.id=a.service_id JOIN staff st ON st.id=a.staff_id WHERE a.id=? AND a.client_id=?',[$id,$clientId])->fetch();
    if (!$row) bookingError('Agendamento não encontrado.');
    return $row;
}
function clientCancel(int $id,int $clientId): void {
    $pdo=db();$pdo->beginTransaction();
    try {
        $row=sql('SELECT * FROM appointments WHERE id=? AND client_id=? FOR UPDATE',[$id,$clientId])->fetch();
        if (!$row) bookingError('Agendamento não encontrado.');
        if (!in_array($row['status'],['pending','confirmed'],true)) bookingError('Este agendamento não pode mais ser cancelado.');
        $limit=(new DateTimeImmutable('now'))->modify('+' . (int)salon()['cancellation_hours'] . ' hours');
        if (new DateTimeImmutable($row['scheduled_date'] . ' ' . $row['starts']) < $limit) bookingError('O prazo para cancelamento online expirou. Fale com o salão.');
        sql("UPDATE appointments SET status='cancelled',cancelled_at=NOW() WHERE id=?",[$id]);
        eventLog($id,'client',$clientId,'cancelled');
        $pdo->commit();
    } catch(Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack();throw $e; }
}
function rescheduleAppointment(int $id,array $data,?int $clientId=null,bool $admin=false): void {
    $date=validDate($data['date'] ?? null);
    $hour=validClock($data['time'] ?? null);
    $requestedStaff=max(0,(int)($data['staff_id'] ?? 0));
    $where=$admin?'id=?':'id=? AND client_id=?';
    $params=$admin?[$id]:[$id,$clientId];
    $old=sql('SELECT service_id FROM appointments WHERE '.$where,$params)->fetch();
    if(!$old)bookingError('Agendamento não encontrado.');
    $service=serviceById((int)$old['service_id']);
    $candidates=professionalsFor((int)$service['id']);
    if($requestedStaff)$candidates=array_filter($candidates,fn($p)=>(int)$p['id']===$requestedStaff);
    if(!$candidates)bookingError('Profissional indisponível para este serviço.');
    $pdo=db();$pdo->beginTransaction();
    try {
        // Important: lock destination day BEFORE appointment row, like normal bookings.
        // This avoids inverted lock order with concurrent booking creation.
        foreach($candidates as $p)lockWorkday((int)$p['id'],$date);
        $a=sql('SELECT * FROM appointments WHERE '.$where.' FOR UPDATE',$params)->fetch();
        if (!$a) bookingError('Agendamento não encontrado.');
        if (!in_array($a['status'],['pending','confirmed'],true)) bookingError('Este agendamento não pode ser reagendado.');
        if (!$admin) {
            $limit=(new DateTimeImmutable('now'))->modify('+' . (int)salon()['cancellation_hours'] . ' hours');
            if (new DateTimeImmutable($a['scheduled_date'].' '.$a['starts']) < $limit) bookingError('O prazo para reagendamento online expirou.');
        }
        // Preserve the originally booked duration when the service was edited later.
        // Otherwise availableSlots could approve a shorter period than the appointment occupies.
        $service['duration_minutes'] = (int)$a['duration_minutes'];
        $staffId=selectStaffForSlot($service,$requestedStaff,$date,$hour,$id);
        sql('UPDATE appointments SET staff_id=?,scheduled_date=?,starts=?,ends=? WHERE id=?',[
            $staffId,$date,$hour,clockOf(minuteOf($hour)+(int)$a['duration_minutes']),$id]);
        eventLog($id,$admin?'admin':'client',$admin?(int)authAdmin()['id']:$clientId,'rescheduled',[
            'from'=>[$a['scheduled_date'],substr($a['starts'],0,5),(int)$a['staff_id']], 'to'=>[$date,$hour,$staffId]]);
        $pdo->commit();
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack();throw $e; }
}
