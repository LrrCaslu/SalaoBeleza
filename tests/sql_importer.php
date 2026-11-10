<?php
declare(strict_types=1);
require dirname(__DIR__).'/scripts/sql_importer.php';
$statements=sqlStatements("-- comentário\nCREATE TABLE `x` (`name` varchar(30));\nINSERT INTO x VALUES ('A; B'), ('O''Brien');\n");
// Os arquivos oficiais usam comentários em linhas separadas; comentários inline não são necessários.
if(count($statements)!==2 || !str_contains($statements[1],"'A; B'")){
  fwrite(STDERR,"FALHOU: divisor SQL com string contendo ponto e vírgula.\n");exit(1);
}
$root=dirname(__DIR__);
foreach(['schema.sql'=>22,'seed.sql'=>9] as $file=>$expected){
 $n=count(sqlStatements(file_get_contents($root.'/database/'.$file)));
 if($n!==$expected){fwrite(STDERR,"FALHOU: $file tem $n instruções; esperado $expected.\n");exit(1);}
 echo "OK: $file dividido em $n instruções SQL.\n";
}
echo "OK: importação SQL pronta.\n";
