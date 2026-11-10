<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Disponível somente no Terminal.'); }
require __DIR__ . '/sql_importer.php';
$project = dirname(__DIR__);
$installed = is_file($project . '/.env');
function out(string $message = ''): void { fwrite(STDOUT, $message . "\n"); }
function question(string $message, string $default = '', bool $hidden = false): string {
    $suffix = $default !== '' && !$hidden ? " [$default]" : '';
    fwrite(STDOUT, "$message$suffix: ");
    if ($hidden && function_exists('shell_exec')) {
        $state = trim((string)@shell_exec('stty -g 2>/dev/null'));
        if ($state !== '') {
            @shell_exec('stty -echo 2>/dev/null');
            try { $line = fgets(STDIN); }
            finally { @shell_exec('stty ' . escapeshellarg($state) . ' 2>/dev/null'); out(); }
        } else $line = fgets(STDIN);
    } else $line = fgets(STDIN);
    if ($line === false) throw new RuntimeException('Entrada interrompida. Abra o Terminal e execute novamente.');
    $answer = trim($line, "\r\n");
    return $answer === '' ? $default : $answer;
}
function validEmailInstall(string $email): bool { return strlen($email) <= 190 && (bool)filter_var($email, FILTER_VALIDATE_EMAIL); }
function guardedPrompt(string $label,string $default,callable $validate,string $message): string {
    for($i=0;$i<5;$i++){ $value=question($label,$default);if($validate($value))return $value;out($message); }
    throw new RuntimeException('Limite de tentativas atingido.');
}
function requireExt(string $extension): void {
    if(!extension_loaded($extension)) throw new RuntimeException("Extensão PHP obrigatória ausente: $extension. Ative PHP 8.3+ e suas extensões no ServBay.");
}
function envValue(string $value): string {
    if (preg_match('/[\r\n]/',$value)) throw new RuntimeException('A configuração não pode conter quebra de linha.');
    return $value;
}
try {
    out('');
    out('✦ ESSENZA BEAUTY STUDIO — INSTALAÇÃO LOCAL SERVBAY ✦');
    out('');
    if ($installed) {
        out('Já existe um arquivo .env. A instalação não será executada para proteger o banco existente.');
        out('Para verificar o ambiente: php scripts/check.php');
        out('Se precisar reconfigurar, faça backup do .env antes de editá-lo.');
        exit(0);
    }
    if(version_compare(PHP_VERSION, '8.3.0', '<'))throw new RuntimeException('O PHP precisa ser 8.3 ou superior. Atual: ' . PHP_VERSION);
    if(!is_writable($project))throw new RuntimeException('Sem permissão para criar o arquivo .env na pasta do projeto.');
    foreach (['pdo_mysql','mbstring','fileinfo','gd'] as $ext) requireExt($ext);
    out('PHP ' . PHP_VERSION . ' • extensões obrigatórias: OK');
    out('Ative o MySQL no ServBay antes de continuar.');
    out('Localize a senha em ServBay → Bancos de Dados → MySQL → Root.');
    out('');
    $dbHost=guardedPrompt('Host MySQL','127.0.0.1',fn($s)=>(bool)preg_match('/^[a-zA-Z0-9.:-]{1,253}$/',$s),'Host inválido.');
    $dbPort=guardedPrompt('Porta MySQL','3306',fn($s)=>ctype_digit($s)&&(int)$s>=1&&(int)$s<=65535,'Porta inválida.');
    $dbName=guardedPrompt('Nome do banco','essenza_beauty',fn($s)=>(bool)preg_match('/^[A-Za-z][A-Za-z0-9_]{0,62}$/',$s),'Use apenas letras, números e underline.');
    $dbUser=guardedPrompt('Usuário MySQL','root',fn($s)=>$s!==''&&strlen($s)<=64&&!str_contains($s,"\n"),'Usuário inválido.');
    $dbPassword=question('Senha do usuário MySQL (pode ficar vazia)', '', true);
    $appUrl=guardedPrompt('Endereço local do site',getenv('ESSENZA_INSTALL_URL') ?: 'http://127.0.0.1:8080',fn($s)=>(bool)filter_var($s,FILTER_VALIDATE_URL)&&in_array(parse_url($s,PHP_URL_SCHEME),['http','https'],true)&&!str_contains($s,'?'),'Informe uma URL http(s) válida.');
    if (parse_url($appUrl,PHP_URL_PATH) && trim((string)parse_url($appUrl,PHP_URL_PATH),'/') !== '') throw new RuntimeException('Configure o site na raiz do domínio, sem subpasta na URL.');
    out('');out('CONTA ADMINISTRATIVA');
    $name=guardedPrompt('Nome da administradora','Equipe Essenza',fn($s)=>mb_strlen($s)>1&&mb_strlen($s)<=120,'Nome inválido.');
    $email=guardedPrompt('E-mail administrativo','admin@essenza.test','validEmailInstall','E-mail inválido.');
    $password='';
    for($i=0;$i<5;$i++){
        $password=question('Crie uma senha administrativa (10 caracteres ou mais)','',true);
        if(strlen($password)>=10&&strlen($password)<=128&&$password===question('Repita a senha administrativa','',true))break;
        $password='';out('As senhas precisam coincidir e ter de 10 a 128 caracteres.');
    }
    if($password==='')throw new RuntimeException('Senha administrativa não configurada.');
    $demo=strtolower(question('Adicionar clientes e agendamentos de teste? (s/N)','n'));
    if(!in_array($demo,['s','n','sim','não','nao'],true))throw new RuntimeException('Opção de demonstração inválida.');
    out('');out('Conectando ao MySQL...');
    $dsn='mysql:host=' . $dbHost . ';port=' . $dbPort . ';charset=utf8mb4';
    $pdo=new PDO($dsn,$dbUser,$dbPassword,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `' . $dbName . '`');
    $created=importSqlFile($pdo,$project.'/database/schema.sql');
    out("Estrutura do banco aplicada: $created comandos SQL.");
    $hasConfig=(int)$pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn();
    if($hasConfig===0){
        $seedCount=importSqlFile($pdo,$project.'/database/seed.sql');
        out("Dados do salão carregados: $seedCount comandos SQL.");
    } else out('Dados existentes preservados; carga inicial ignorada.');
    $sql=$pdo->prepare('SELECT id FROM admins WHERE email=?');$sql->execute([mb_strtolower($email)]);
    if($sql->fetchColumn())throw new RuntimeException('Já existe um administrador com esse e-mail. Tente novamente com outro e-mail.');
    $stmt=$pdo->prepare("INSERT INTO admins(name,email,password_hash,role,active) VALUES (?,?,?,'owner',1)");
    $stmt->execute([$name,mb_strtolower($email),password_hash($password,defined('PASSWORD_ARGON2ID')?PASSWORD_ARGON2ID:PASSWORD_DEFAULT)]);
    $lines=[
      '# Gerado pelo instalador Essenza para uso local. Nunca publique este arquivo.',
      'APP_ENV=local', 'APP_DEBUG=true', 'APP_URL=' . envValue(rtrim($appUrl,'/')), 'APP_AUTO_URL=true',
      'DB_HOST=' . $dbHost, 'DB_PORT=' . $dbPort, 'DB_NAME=' . $dbName,
      'DB_USER=' . envValue($dbUser), 'DB_PASSWORD_BASE64=' . base64_encode($dbPassword),
      'SESSION_NAME=essenza_sid',
    ];
    $envFile=$project.'/.env';
    if(file_put_contents($envFile,implode("\n",$lines)."\n",LOCK_EX)===false)throw new RuntimeException('Falha ao criar .env. Confira as permissões da pasta do projeto.');
    @chmod($envFile,0600);
    if(in_array($demo,['s','sim'],true)){
        out('');out('Gerando agendamentos e clientes de exemplo...');
        // O seed usa a mesma configuração .env, atualizando as variáveis para o processo atual.
        foreach(['APP_URL'=>rtrim($appUrl,'/'),'DB_HOST'=>$dbHost,'DB_PORT'=>$dbPort,'DB_NAME'=>$dbName,'DB_USER'=>$dbUser,'DB_PASSWORD_BASE64'=>base64_encode($dbPassword)] as $key=>$value)putenv($key.'='.$value);
        $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($project.'/scripts/seed_demo.php');
        passthru($cmd,$code);
        if($code!==0)out('Aviso: os dados extras de demonstração não foram totalmente gerados. O site já está instalado.');
    }
    out('');out('INSTALAÇÃO CONCLUÍDA!');
    out('Site: ' . rtrim($appUrl,'/'));
    out('Painel: ' . rtrim($appUrl,'/') . '/admin/login');
    out('Login administrativo: ' . $email);
    out('Use a senha administrativa que você acabou de cadastrar.');
    out('Não compartilhe o .env e altere os contatos fictícios antes de publicar.');
    out('');
} catch(Throwable $exception){
    fwrite(STDERR,"\nERRO DE INSTALAÇÃO: ".$exception->getMessage()."\n");
    fwrite(STDERR,"Verifique a senha e a porta do MySQL em ServBay → Bancos de Dados.\n");
    exit(1);
}
