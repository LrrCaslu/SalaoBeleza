<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli'){http_response_code(403);exit('Somente CLI.');}
require dirname(__DIR__).'/src/core.php';
$root=dirname(__DIR__);$errors=[];
echo "\nESSENZA • DIAGNÓSTICO LOCAL DO SERVBAY\n";
$versionOk=version_compare(PHP_VERSION,'8.3.0','>=');
echo 'PHP: '.PHP_VERSION.' ('.($versionOk?'OK':'ATUALIZE').")\n";
if(!$versionOk)$errors[]='PHP 8.3 ou superior é obrigatório.';
foreach(['pdo_mysql','mbstring','fileinfo','gd'] as $ext){
  $ok=extension_loaded($ext);echo "$ext: ".($ok?'OK':'AUSENTE')."\n";
  if(!$ok)$errors[]="Extensão PHP $ext não está habilitada.";
}
$env=is_file($root.'/.env');
echo 'Arquivo .env: '.($env?'OK':'AUSENTE')."\n";
if(!$env)$errors[]='Execute o instalador para configurar .env.';
$uploads=$root.'/public/assets/img/uploads';
$uploadOk=is_dir($uploads)&&is_writable($uploads);
echo 'Uploads: '.($uploadOk?'OK':'SEM PERMISSÃO DE ESCRITA')."\n";
if(!$uploadOk)$errors[]='Dê permissão de escrita à pasta public/assets/img/uploads.';
if($env&&extension_loaded('pdo_mysql')){
 try{
  $server=db()->query('SELECT VERSION() v')->fetch();
  echo 'Servidor SQL: '.$server['v']."\n";
  $tables=['settings','clients','admins','appointments','staff','services','staff_hours','booking_day_locks'];
  foreach($tables as $table){
   $count=(int)db()->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
   echo "$table: $count registro(s)\n";
   if(in_array($table,['settings','services','staff'],true)&&$count===0)$errors[]="A tabela $table está vazia; importação inicial incompleta.";
   if($table==='admins'&&$count===0)$errors[]='Nenhum administrador cadastrado.';
  }
  echo "Conexão e consultas ao banco: OK\n";
 }catch(Throwable $e){$errors[]='Falha de conexão ou tabela: '.$e->getMessage();}
}
if($errors){
 fwrite(STDERR,"\nAJUSTES NECESSÁRIOS:\n");foreach($errors as $e)fwrite(STDERR," - $e\n");exit(1);
}
echo "\n✓ AMBIENTE PRONTO: abra o site e teste um agendamento.\n\n";
