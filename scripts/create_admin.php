<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(403);exit('Somente CLI.');}
require dirname(__DIR__).'/src/core.php';
if($argc<4) {fwrite(STDERR,"Uso: php scripts/create_admin.php email@exemplo.com 'SenhaForteCom10+' 'Nome Completo' [owner|manager|reception]\n");exit(1);}
[$script,$email,$password,$name]=$argv;
$role=$argv[4]??'owner';
if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($password)<10||strlen($password)>128||trim($name)===''||!in_array($role,['owner','manager','reception'],true)){
 fwrite(STDERR,"E-mail, nome, senha ou perfil inválido. Senha: 10 a 128 caracteres.\n");exit(1);
}
try{
 $exists=sql('SELECT id FROM admins WHERE email=?',[$email])->fetchColumn();
 if($exists){fwrite(STDERR,"Este e-mail já pertence a um administrador. Não foi alterado.\n");exit(1);}
 sql('INSERT INTO admins(name,email,password_hash,role,active) VALUES (?,?,?,?,1)',[trim($name),strtolower($email),passHash($password),$role]);
 echo "Administrador cadastrado com sucesso. Perfil: $role | E-mail: $email\n";
}catch(Throwable $e){fwrite(STDERR,"Erro: ".$e->getMessage()."\n");exit(1);}
