<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/booking.php';
$checks=[
    ['09:30 são 570 minutos',minuteOf('09:30')===570],
    ['Converte 570 minutos em 09:30',clockOf(570)==='09:30'],
    ['Agendas adjacentes não se sobrepõem',!intersects(9*60,10*60,10*60,11*60)],
    ['Agendas com um minuto em comum se sobrepõem',intersects(9*60,10*60,9*60+59,11*60)],
    ['Serviço de 60 minutos cruza pausa iniciada às 12h',intersects(11*60+30,12*60+30,12*60,13*60)],
    ['Inícios iguais geram sobreposição',intersects(14*60,15*60,14*60,14*60+30)],
];
foreach($checks as [$description,$okay]){
    if(!$okay){fwrite(STDERR,"FALHOU: $description\n");exit(1);}
    echo "OK: $description\n";
}
echo "Todos os testes de regra temporal passaram.\n";
