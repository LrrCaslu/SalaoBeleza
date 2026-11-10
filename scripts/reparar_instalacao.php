<?php
declare(strict_types=1);
/** Ajusta a conexão de uma instalação local existente sem apagar dados. CLI somente. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Acesso apenas via terminal.'); }
require __DIR__ . '/sql_importer.php';
$root = dirname(__DIR__);
$envFile = $root . '/.env';
if (!is_file($envFile)) {
    fwrite(STDERR, "Arquivo .env ausente. Execute php scripts/instalar.php primeiro.\n");
    exit(1);
}
function say(string $message = ''): void { fwrite(STDOUT, $message . "\n"); }
function ask(string $message, string $default = ''): string {
    fwrite(STDOUT, $message . ($default === '' ? '' : " [$default]") . ': ');
    $value = fgets(STDIN);
    if ($value === false) throw new RuntimeException('Entrada interrompida.');
    $value = trim($value, "\r\n");
    return $value === '' ? $default : $value;
}
function configLines(string $file): array {
    $result = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', trim($line), $m)) {
            $result[$m[1]] = trim($m[2], " \t\r\n\"'");
        }
    }
    return $result;
}
function saveEnv(string $file, array $data): void {
    $content = file_get_contents($file);
    if ($content === false) throw new RuntimeException('Não foi possível ler o .env.');
    foreach ($data as $key => $value) {
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key) || preg_match('/[\r\n]/', $value)) {
            throw new RuntimeException('Valor inválido nas configurações.');
        }
        $line = $key . '=' . $value;
        if (preg_match('/^' . preg_quote($key, '/') . '=/m', $content)) {
            $content = preg_replace('/^' . preg_quote($key, '/') . '=[^\r\n]*/m', $line, $content) ?? $content;
        } else {
            $content = rtrim($content, "\r\n") . "\n" . $line . "\n";
        }
    }
    $backup = $file . '.backup-' . date('Ymd-His');
    if (!copy($file, $backup)) throw new RuntimeException('Não foi possível salvar backup do .env.');
    if (file_put_contents($file, $content, LOCK_EX) === false) throw new RuntimeException('Não foi possível gravar o .env.');
    @chmod($file, 0600);
    say('Configuração anterior preservada em ' . basename($backup) . '.');
}
try {
    if (!extension_loaded('pdo_mysql')) throw new RuntimeException('Extensão PHP pdo_mysql não está habilitada.');
    $current = configLines($envFile);
    $oldPassword = isset($current['DB_PASSWORD_BASE64'])
        ? base64_decode($current['DB_PASSWORD_BASE64'], true)
        : ($current['DB_PASSWORD'] ?? '');
    if ($oldPassword === false) $oldPassword = '';
    say('');
    say('ESSENZA - RECUPERACAO DA CONEXAO MYSQL (SERVBAY WINDOWS)');
    say('Seus agendamentos, clientes e configurações não serão apagados.');
    say('Confira o MySQL no painel ServBay: status ligado e porta de conexão.');
    say('');
    $host = ask('Host do MySQL', $current['DB_HOST'] ?? '127.0.0.1');
    $port = ask('Porta do MySQL', $current['DB_PORT'] ?? '3306');
    $name = ask('Banco de dados', $current['DB_NAME'] ?? 'essenza_beauty');
    $user = ask('Usuário MySQL', $current['DB_USER'] ?? 'root');
    if (!preg_match('/^[a-zA-Z0-9.:-]{1,253}$/', $host) ||
        !ctype_digit($port) || (int)$port < 1 || (int)$port > 65535 ||
        !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,62}$/', $name) ||
        $user === '' || strlen($user) > 64) throw new RuntimeException('Host, porta, banco ou usuário inválido.');
    $password = ask('Senha MySQL (Enter = manter senha atual)');
    if ($password === '') $password = $oldPassword;
    $dsn = "mysql:host=$host;port=$port;charset=utf8mb4";
    say('Conectando ao MySQL...');
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `' . $name . '`');
    importSqlFile($pdo, $root . '/database/schema.sql');
    $settings = (int)$pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn();
    if ($settings === 0) {
        importSqlFile($pdo, $root . '/database/seed.sql');
        say('Banco inicializado com serviços e equipe de exemplo.');
    } else {
        say('Dados existentes encontrados e preservados.');
    }
    $admins = (int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
    if ($admins === 0) {
        say('');
        say('Ainda não existe uma conta administrativa. Crie a primeira agora.');
        $adminName = ask('Nome da administradora', 'Equipe Essenza');
        $email = ask('Email administrativo', 'admin@essenza.test');
        if (mb_strlen($adminName) < 2 || mb_strlen($adminName) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Nome ou e-mail administrativo inválido.');
        }
        $adminPassword = ask('Senha administrativa (10 caracteres ou mais)');
        $repeat = ask('Repita a senha administrativa');
        if (strlen($adminPassword) < 10 || strlen($adminPassword) > 128 || $adminPassword !== $repeat) {
            throw new RuntimeException('As senhas administrativas precisam coincidir e ter pelo menos 10 caracteres.');
        }
        $stmt = $pdo->prepare("INSERT INTO admins(name,email,password_hash,role,active) VALUES (?,?,?,'owner',1)");
        $stmt->execute([$adminName, mb_strtolower($email), password_hash($adminPassword, PASSWORD_DEFAULT)]);
    }
    saveEnv($envFile, [
        'APP_ENV' => 'local',
        'APP_AUTO_URL' => 'true',
        'DB_HOST' => $host,
        'DB_PORT' => $port,
        'DB_NAME' => $name,
        'DB_USER' => $user,
        'DB_PASSWORD' => '',
        'DB_PASSWORD_BASE64' => base64_encode($password),
    ]);
    say('');
    say('CONEXAO MYSQL AJUSTADA COM SUCESSO!');
    say('Volte ao navegador do ServBay e atualize com Ctrl+F5.');
    say('Painel do salão: /admin/login');
} catch (Throwable $e) {
    fwrite(STDERR, "\nFALHA NA RECUPERACAO: " . $e->getMessage() . "\n");
    fwrite(STDERR, "O arquivo .env só é atualizado após a conexão e importação concluírem.\n");
    exit(1);
}
