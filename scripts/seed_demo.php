<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Somente CLI.');}
require dirname(__DIR__).'/src/core.php';
require dirname(__DIR__).'/src/booking.php';
$demoPassword='Cliente@12345';
$people=[
 ['Mariana Costa','48999990001','mariana@example.invalid'],
 ['Juliana Rocha','48999990002','juliana@example.invalid'],
 ['Carla Mendes','48999990003','carla@example.invalid'],
 ['Ana Souza','48999990004','ana@example.invalid'],
];
try {
 $ids=[];
 foreach($people as [$name,$phone,$email]){
  $id=sql('SELECT id FROM clients WHERE phone=?',[$phone])->fetchColumn();
  if(!$id){sql('INSERT INTO clients(name,phone,email,password_hash,consent_at) VALUES (?,?,?,?,NOW())',[$name,$phone,$email,passHash($demoPassword)]);$id=db()->lastInsertId();}
  $ids[]=(int)$id;
 }
 $base=new DateTimeImmutable('tomorrow');
 $dates=[];
 for($i=0;count($dates)<4 && $i<12;$i++){
  $d=$base->modify("+$i days");
  $weekday=(int)$d->format('w');
  if($weekday!==0 && !sql('SELECT closed FROM salon_hours WHERE weekday=?',[$weekday])->fetchColumn())$dates[]=$d->format('Y-m-d');
 }
 $items=[
  ['client_id'=>$ids[0],'service_id'=>4,'staff_id'=>4,'date'=>$dates[0],'time'=>'10:00'],
  ['client_id'=>$ids[1],'service_id'=>1,'staff_id'=>1,'date'=>$dates[0],'time'=>'11:00'],
  ['client_id'=>$ids[2],'service_id'=>7,'staff_id'=>3,'date'=>$dates[1],'time'=>'14:00'],
  ['client_id'=>$ids[3],'service_id'=>2,'staff_id'=>2,'date'=>$dates[2],'time'=>'10:00'],
 ];
 foreach($items as $item){
  if(sql('SELECT 1 FROM appointments WHERE client_id=? AND scheduled_date=? AND starts=?',[$item['client_id'],$item['date'],$item['time']])->fetch())continue;
  try{$result=makeAppointment($item,$item['client_id']);echo "Criado: {$result['code']} - {$item['date']} {$item['time']}\n";}
  catch(BookingError $e){echo "Ignorado ({$item['date']} {$item['time']}): {$e->getMessage()}\n";}
 }
 $history=sql("SELECT id FROM appointments WHERE client_id=? AND status='completed' LIMIT 1",[$ids[0]])->fetchColumn();
 if(!$history){
  $past=(new DateTimeImmutable('yesterday'));
  for($i=0;$i<7 && (int)$past->format('w')===0;$i++)$past=$past->modify('-1 day');
  $service=serviceById(1);$date=$past->format('Y-m-d');$code='ESZ-'.strtoupper(bin2hex(random_bytes(10)));
  sql("INSERT INTO appointments(confirmation_code,client_id,staff_id,service_id,scheduled_date,starts,ends,price_snapshot,duration_minutes,client_name_snapshot,client_phone_snapshot,client_email_snapshot,status)
  VALUES (?,?,?,?,?,?,?,?,?,?,?,?, 'completed')",[$code,$ids[0],1,1,$date,'10:00:00','11:00:00',$service['price'],60,$people[0][0],$people[0][1],$people[0][2]]);
  eventLog((int)db()->lastInsertId(),'system',null,'demo_completed');
  echo "Atendimento anterior fictício registrado.\n";
 }
 echo "\nClientes de demonstração:\n";
 foreach($people as [$name,$phone])echo "$name | $phone | Senha: $demoPassword\n";
 echo "Os números e e-mails são apenas fictícios. Não utilize o banco de demonstração em produção.\n";
}catch(Throwable $e){fwrite(STDERR,'Erro ao gerar dados demo: '.$e->getMessage()."\n");exit(1);}
