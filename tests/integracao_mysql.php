<?php
declare(strict_types=1);
/** Teste não destrutivo: cliente/agendamento são inseridos numa transação revertida. */
if (PHP_SAPI!=='cli'){http_response_code(403);exit('Somente CLI.');}
require dirname(__DIR__).'/src/core.php';
require dirname(__DIR__).'/src/booking.php';
try {
    salon();
    $row=sql('SELECT s.id service_id,ss.staff_id FROM services s JOIN staff_services ss ON ss.service_id=s.id JOIN staff st ON st.id=ss.staff_id WHERE s.active=1 AND st.active=1 ORDER BY s.id LIMIT 1')->fetch();
    if (!$row)throw new RuntimeException('Configure um serviço e um profissional ativo para testar.');
    $service=serviceById((int)$row['service_id']);
    $staffId=(int)$row['staff_id'];
    $today=new DateTimeImmutable('today');$selection=null;
    for($i=0;$i<90;$i++) {
        $date=$today->modify('+' . $i . ' days')->format('Y-m-d');
        $slots=slotsForStaff($service,$staffId,$date);
        if($slots) {$selection=['date'=>$date,'time'=>$slots[0]];break;}
    }
    if(!$selection)throw new RuntimeException('Não há agenda livre nos próximos 90 dias; verifique as jornadas do salão.');
    $pdo=db();$pdo->beginTransaction();
    try {
        $phone='48'.random_int(90000000,99999999);
        sql('INSERT INTO clients(name,phone,password_hash,consent_at) VALUES (?,?,?,NOW())',['Teste temporário de integração',$phone,passHash('NaoUtilizar!12345')]);
        $clientId=(int)$pdo->lastInsertId();
        $from=$selection['time'];$until=clockOf(minuteOf($from)+(int)$service['duration_minutes']);
        sql("INSERT INTO appointments(confirmation_code,client_id,staff_id,service_id,scheduled_date,starts,ends,price_snapshot,duration_minutes,client_name_snapshot,client_phone_snapshot,status)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,'confirmed')",[ 'TEST-'.strtoupper(bin2hex(random_bytes(9))),$clientId,$staffId,$service['id'],$selection['date'],$from,$until,$service['price'],$service['duration_minutes'],'Teste temporário de integração',$phone]);
        $slotsAfter=slotsForStaff($service,$staffId,$selection['date'],true);
        if(in_array($from,$slotsAfter,true))throw new RuntimeException('Falha: o horário ocupado ainda aparece como disponível!');
        foreach($slotsAfter as $slot)if(intersects(minuteOf($from),minuteOf($until),minuteOf($slot),minuteOf($slot)+(int)$service['duration_minutes']))throw new RuntimeException('Falha: há um horário sobreposto no calendário.');
        echo "OK: reserva de $from a $until em {$selection['date']} bloqueou horários conflitantes.\n";
        echo "OK: SQL, PDO, disponibilidade, hash de senha e restrições integrados.\n";
    } finally { if($pdo->inTransaction())$pdo->rollBack(); }
    echo "OK: dados temporários revertidos; nenhuma reserva de teste foi persistida.\n";
} catch(Throwable $e) { fwrite(STDERR,'FALHOU: '.$e->getMessage()."\n");exit(1); }
