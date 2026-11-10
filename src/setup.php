<?php
declare(strict_types=1);

/**
 * Configuração web de primeira execução, apenas a partir do próprio computador.
 * Não abre o banco ou cria usuários sem validar a conexão e a integridade do SQL.
 * O segredo de configuração local é removido após provisionar uma conta MySQL dedicada.
 */

function setupIsLocal(): bool {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return in_array($ip, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true);
}
function setupProject(): string { return dirname(__DIR__); }
function setupDefaults(): array {
    $localFile = __DIR__ . '/setup_local_credentials.php';
    $defaults = is_file($localFile) ? require $localFile : [];
    return array_merge([
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => 'essenza_beauty',
        'username' => 'root',
        'password' => '',
    ], is_array($defaults) ? $defaults : []);
}
function setupDbAvailable(PDO $pdo): array {
    $check = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('settings','admins')");
    if ((int)$check->fetchColumn() !== 2) return ['tables' => false, 'admins' => 0];
    $count = (int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
    $settings = (int)$pdo->query('SELECT COUNT(*) FROM settings WHERE id=1')->fetchColumn();
    return ['tables' => true, 'admins' => $count, 'settings' => $settings];
}
function setupLocalUrl(): string {
    if (PHP_SAPI === 'cli') return 'http://127.0.0.1';
    $host = (string)($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
    if (!preg_match('/\A(?:[a-zA-Z0-9.-]+|\[[0-9a-fA-F:]+\])(?::[0-9]{1,5})?\z/D', $host)) $host = '127.0.0.1';
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return ($https ? 'https' : 'http') . '://' . $host;
}
function setupSaveEnv(array $values): void {
    $path = setupProject().'/.env';
    $lines = ['# Configuração local gerada automaticamente pelo Essenza.', '# PRIVADO: mantenha esta pasta fora da raiz pública do ServBay.'];
    foreach ($values as $key => $value) {
        if (!preg_match('/\A[A-Z][A-Z0-9_]*\z/', $key) || preg_match('/[\r\n]/', $value)) throw new RuntimeException('Configuração inválida.');
        $lines[] = $key . '=' . $value;
    }
    $tmp = $path . '.new.' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, implode("\n", $lines)."\n", LOCK_EX) === false) throw new RuntimeException('Sem permissão para gravar o arquivo de configuração no diretório do projeto.');
    @chmod($tmp, 0600);
    if (is_file($path)) {
        $backup = $path . '.backup.' . date('Ymd-His');
        if (!copy($path, $backup)) { @unlink($tmp); throw new RuntimeException('Não foi possível preservar o .env atual.'); }
        @chmod($backup, 0600);
    }
    if (!@rename($tmp, $path)) {
        // O Windows pode recusar substituir um arquivo existente via rename().
        $content = file_get_contents($tmp);
        if ($content === false || file_put_contents($path, $content, LOCK_EX) === false) {
            @unlink($tmp);
            throw new RuntimeException('Não foi possível instalar o .env. Verifique a permissão da pasta.');
        }
        @unlink($tmp);
    }
    @chmod($path, 0600);
    foreach ($values as $key => $value) putenv($key.'='.$value);
}
function setupConnection(array $params): PDO {
    $host = trim((string)$params['host']);
    $port = trim((string)$params['port']);
    $name = trim((string)$params['database']);
    $username = trim((string)$params['username']);
    if (!preg_match('/\A(?:127\.0\.0\.1|localhost|::1)\z/i', $host)) throw new RuntimeException('O instalador direto é restrito ao MySQL local.');
    if (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) throw new RuntimeException('Porta MySQL inválida.');
    if (!preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,62}\z/', $name)) throw new RuntimeException('Nome de banco inválido.');
    if ($username === '' || strlen($username) > 64 || preg_match('/[\r\n]/', $username)) throw new RuntimeException('Usuário MySQL inválido.');
    return new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $username, (string)$params['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 4,
    ]);
}
function setupInitialize(array $params): void {
    require_once setupProject().'/scripts/sql_importer.php';
    if (!extension_loaded('pdo_mysql')) throw new RuntimeException('A extensão PHP pdo_mysql não está habilitada no ServBay.');
    if (!extension_loaded('mbstring')) throw new RuntimeException('A extensão PHP mbstring não está habilitada no ServBay.');
    $project = setupProject();
    $handle = @fopen($project.'/.essenza-install.lock', 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) throw new RuntimeException('Não foi possível bloquear a configuração. Confira a permissão da pasta.');
    try {
        // Não refazer configurações já instaladas (pode haver requisições paralelas).
        try {
            if (is_file($project.'/.env')) {
                // Releia o arquivo se outra requisição terminou a instalação.
                loadEnv($project.'/.env');
                $existingState = setupDbAvailable(db());
                if (!empty($existingState['tables']) && !empty($existingState['settings'])) return;
            }
        } catch (Throwable $ignored) { /* .env anterior pode estar incorreto */ }

        $root = setupConnection($params);
        $name = (string)$params['database'];
        $root->exec('CREATE DATABASE IF NOT EXISTS `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $root->exec('USE `'.$name.'`');
        // Importe somente o necessário; não apague nem substitua registros existentes.
        importSqlFile($root, $project.'/database/schema.sql');
        $hasSettings = (int)$root->query('SELECT COUNT(*) FROM settings WHERE id=1')->fetchColumn();
        if ($hasSettings === 0) importSqlFile($root, $project.'/database/seed.sql');

        // A aplicação não guarda nem usa a senha root; cria um login exclusivo para o banco do salão.
        $appUser = 'essenza_'.bin2hex(random_bytes(6));
        $appPassword = bin2hex(random_bytes(24));
        $account = $root->quote($appUser).'@'.$root->quote('127.0.0.1');
        $root->exec('CREATE USER '.$account.' IDENTIFIED BY '.$root->quote($appPassword));
        $root->exec('GRANT SELECT, INSERT, UPDATE, DELETE ON `'.$name.'`.* TO '.$account);
        setupSaveEnv([
            'APP_ENV' => 'local',
            'APP_DEBUG' => 'false',
            'APP_URL' => setupLocalUrl(),
            'APP_AUTO_URL' => 'true',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => (string)$params['port'],
            'DB_NAME' => $name,
            'DB_USER' => $appUser,
            'DB_PASSWORD_BASE64' => base64_encode($appPassword),
            'SESSION_NAME' => 'essenza_sid',
        ]);
        // Evita manter uma senha privilegiada no diretório de uma instalação concluída.
        @unlink(__DIR__.'/setup_local_credentials.php');
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
function setupPage(string $stage, ?string $error = null, array $defaults = []): never {
    http_response_code($error === null ? 200 : 503);
    header('Content-Type: text/html; charset=utf-8');
    $settings = setupDefaults();
    if (!empty($defaults)) $settings = array_merge($settings, $defaults);
    $action = e((string)($_SERVER['REQUEST_URI'] ?? '/'));
    $hostname = e((string)($_SERVER['HTTP_HOST'] ?? 'site local'));
    $isAdmin = $stage === 'admin';
    $title = $isAdmin ? 'Crie o acesso ao painel' : 'Conecte o MySQL local';
    $description = $isAdmin ? 'O banco de dados já está pronto. Falta apenas criar a conta que vai administrar o salão.' : 'O ServBay está atendendo o site, mas ainda não foi possível finalizar a conexão ao MySQL.';
    $errorHtml = $error ? '<div class="error" role="alert">'.e($error).'</div>' : '';
    $dbinfo = e((string)$settings['database']);
    $host = e((string)$settings['host']);
    $port = e((string)$settings['port']);
    $username = e((string)$settings['username']);
    $token = e(csrfToken());
    echo <<<HTML
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Configuração local · Essenza</title>
<style>
:root{color-scheme:light;font-family:system-ui,-apple-system,Segoe UI,sans-serif;color:#40312e;background:#f9f4f0}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:radial-gradient(circle at 15% 5%,#f2ddd8 0,transparent 38%),#f9f4f0}.wrap{width:min(94%,630px);margin:30px auto;background:rgba(255,255,255,.94);border:1px solid #eddfd8;border-radius:20px;padding:38px;box-shadow:0 16px 50px #6f4f4430}.eyebrow{font-size:11px;letter-spacing:4px;text-transform:uppercase;color:#a67f70;font-weight:700}.mark{color:#ad7c69;font-size:32px}h1{font-family:Georgia,serif;line-height:1.16;font-weight:400;font-size:clamp(30px,5vw,44px);margin:12px 0}p{color:#766962;line-height:1.6}form{display:grid;gap:14px;margin-top:24px}label{font-size:13px;font-weight:600;color:#594944}input{margin-top:7px;display:block;width:100%;padding:13px 14px;background:#fdfafa;border:1px solid #d9c9c0;border-radius:10px;font:inherit;color:#3b3030}input:focus{outline:2px solid #c3998b;outline-offset:1px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}button{background:#b88476;border:0;color:#fff;border-radius:11px;padding:15px 20px;font-size:15px;font-weight:700;cursor:pointer}button:hover{background:#9c6b61}.error{background:#fff1ed;color:#873f39;border:1px solid #eab1a4;padding:12px;border-radius:10px;margin-top:15px;line-height:1.45}.meta{margin-top:22px;padding-top:16px;border-top:1px solid #efe5e0;font-size:12px;color:#8f7e77}.info{background:#f8f0ec;color:#73584f;padding:10px;border-radius:9px;font-size:13px;margin-top:16px}@media(max-width:520px){.wrap{padding:23px}.grid{grid-template-columns:1fr}}
</style></head><body><main class="wrap"><div class="mark">✿</div><div class="eyebrow">Essenza · Beauty Studio</div><h1>$title</h1><p>$description</p>$errorHtml
HTML;
    if ($isAdmin) {
        echo <<<HTML
<div class="info">✓ Estrutura SQL instalada · Banco {$dbinfo} · Próximo passo: acesso seguro</div>
<form method="post" action="$action" autocomplete="on"><input type="hidden" name="setup_action" value="admin"><input type="hidden" name="_csrf" value="$token"><label>Seu nome completo<input type="text" name="name" maxlength="120" placeholder="Responsável pelo salão" autocomplete="name" required></label><label>E-mail administrativo<input type="email" name="email" maxlength="190" placeholder="seu-email@exemplo.com" autocomplete="email" required></label><label>Crie uma senha (mínimo 10 caracteres)<input type="password" name="password" minlength="10" maxlength="128" autocomplete="new-password" required></label><label>Confirme a senha<input type="password" name="password_confirmation" minlength="10" maxlength="128" autocomplete="new-password" required></label><button type="submit">Criar acesso e entrar no painel →</button></form>
HTML;
    } else {
        echo <<<HTML
<div class="info">Confira se o serviço MySQL 8.4 está iniciado no ServBay. A configuração automática usa o MySQL local.</div>
<form method="post" action="$action"><input type="hidden" name="setup_action" value="database"><input type="hidden" name="_csrf" value="$token"><div class="grid"><label>Servidor<input name="host" value="$host" required></label><label>Porta<input name="port" inputmode="numeric" value="$port" required></label></div><label>Banco de dados<input name="database" value="$dbinfo" required></label><label>Usuário MySQL<input name="username" value="$username" required></label><label>Senha MySQL<input type="password" name="password" autocomplete="off" placeholder="Senha mostrada no ServBay"></label><button type="submit">Preparar banco e continuar →</button></form>
HTML;
    }
    echo '<div class="meta">Site: '.$hostname.' · Esta configuração inicial só pode ser concluída a partir deste computador.</div></main></body></html>';
    exit;
}
function setupGate(): void {
    // O CLI não usa instalação automática.
    if (PHP_SAPI === 'cli') return;
    $database = null;
    $status = null;
    $project = setupProject();
    if (extension_loaded('pdo_mysql')) {
        try {
            $database = db();
            $status = setupDbAvailable($database);
        } catch (Throwable $e) {
            error_log('[Essenza setup] Banco ainda não configurado: '.$e->getMessage());
        }
    }
    if ($status !== null && !empty($status['tables']) && !empty($status['admins']) && !empty($status['settings'])) {
        @unlink(__DIR__.'/setup_local_credentials.php');
        return;
    }
    if (!setupIsLocal()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Configuração inicial restrita ao acesso local no próprio computador.');
    }
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'POST'], true)) {
        http_response_code(405); header('Allow: GET, POST'); exit('Método não permitido.');
    }
    $post = $method === 'POST' ? $_POST : [];
    $action = (string)($post['setup_action'] ?? '');
    if ($method === 'POST' && $action !== '') {
        if (!is_string($post['_csrf'] ?? null) || !hash_equals(csrfToken(), $post['_csrf'])) {
            setupPage($status && !empty($status['tables']) ? 'admin' : 'database', 'A sessão expirou. Atualize a página e tente novamente.');
        }
    }

    $error = null;
    // Se a configuração atual já está conectada, apenas falta instalar esquema e/ou administrador.
    if ($database instanceof PDO && ($status === null || empty($status['tables']) || empty($status['settings']))) {
        try {
            require_once $project.'/scripts/sql_importer.php';
            importSqlFile($database, $project.'/database/schema.sql');
            if ((int)$database->query('SELECT COUNT(*) FROM settings WHERE id=1')->fetchColumn() === 0) {
                importSqlFile($database, $project.'/database/seed.sql');
            }
            $status = setupDbAvailable($database);
        } catch (Throwable $e) {
            error_log('[Essenza setup] Esquema: '.$e->getMessage());
            $database = null;
            $status = null;
            $error = 'Não foi possível preparar a estrutura do banco com as permissões atuais.';
        }
    }
    if ($status === null || empty($status['tables'])) {
        if (!extension_loaded('pdo_mysql')) setupPage('database', 'Falta a extensão pdo_mysql no PHP selecionado no ServBay.');
        if (!extension_loaded('mbstring')) setupPage('database', 'Falta a extensão mbstring no PHP selecionado no ServBay.');
        $defaults = setupDefaults();
        if (is_file($project.'/.env')) {
            $defaults['database'] = settingEnv('DB_NAME', 'essenza_beauty');
            $defaults['port'] = settingEnv('DB_PORT', '3306');
        }
        if ($action === 'database') {
            $defaults = [
                'host' => (string)($post['host'] ?? ''),
                'port' => (string)($post['port'] ?? ''),
                'database' => (string)($post['database'] ?? ''),
                'username' => (string)($post['username'] ?? ''),
                'password' => (string)($post['password'] ?? ''),
            ];
        }
        // Automático somente em GET; em POST usa credenciais informadas no formulário.
        if ($method === 'GET' || $action === 'database') {
            try {
                setupInitialize($defaults);
                // Nova requisição garante uma conexão PDO com as novas credenciais.
                header('Location: '.setupLocalUrl().'/', true, 303);
                exit;
            } catch (Throwable $e) {
                error_log('[Essenza setup] Provisionamento: '.$e->getMessage());
                $error = 'Não foi possível conectar ou configurar o MySQL. Confira o serviço, porta e usuário. Detalhe técnico: '.
                    (str_contains($e->getMessage(), 'pdo_mysql') ? 'extensão pdo_mysql ausente.' : 'credenciais ou permissões do MySQL.');
            }
        }
        if ($status === null || empty($status['tables'])) setupPage('database', $error, $defaults);
    }
    if ($status !== null && !empty($status['admins'])) {
        // A configuração foi recuperada e já existe uma conta: abrir o site normalmente.
        return;
    }
    if ($method === 'POST' && $action === 'admin') {
        $name = trim((string)($post['name'] ?? ''));
        $email = mb_strtolower(trim((string)($post['email'] ?? '')));
        $password = (string)($post['password'] ?? '');
        $confirmation = (string)($post['password_confirmation'] ?? '');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120 || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Informe seu nome e um e-mail administrativo válido.';
        } elseif (strlen($password) < 10 || strlen($password) > 128 || $password !== $confirmation) {
            $error = 'A senha precisa ter entre 10 e 128 caracteres e coincidir com a confirmação.';
        } else {
            try {
                $locked = (int)$database->query("SELECT GET_LOCK('essenza_first_owner', 10)")->fetchColumn();
                if ($locked !== 1) throw new RuntimeException('Não foi possível obter o bloqueio de segurança.');
                try {
                    if ((int)$database->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0) {
                        throw new RuntimeException('A conta administrativa já foi criada. Entre pelo painel.');
                    }
                    $stmt = $database->prepare("INSERT INTO admins(name,email,password_hash,role,active) VALUES(?,?,?,'owner',1)");
                    $stmt->execute([$name, $email, passHash($password)]);
                    $id = (int)$database->lastInsertId();
                } finally {
                    $database->query("SELECT RELEASE_LOCK('essenza_first_owner')")->closeCursor();
                }
                @unlink(__DIR__.'/setup_local_credentials.php');
                signIn('admin', $id);
                header('Location: '.url('admin'), true, 303);
                exit;
            } catch (Throwable $e) {
                error_log('[Essenza setup] Administrador: '.$e->getMessage());
                $error = 'Não foi possível criar a conta. Verifique se ela já foi cadastrada.';
            }
        }
    }
    setupPage('admin', $error);
}
