<?php
declare(strict_types=1);

function loadEnv(string $file): void {
    if (!is_file($file)) return;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key) || getenv($key) !== false) continue;
        putenv($key . '=' . trim($value, " \t\n\r\0\x0B\"'"));
    }
}
loadEnv(dirname(__DIR__) . '/.env');

function settingEnv(string $key, string $default = ''): string {
    $value = getenv($key);
    return $value === false ? $default : (string)$value;
}

date_default_timezone_set('America/Sao_Paulo');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
$secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    session_name(settingEnv('SESSION_NAME', 'essenza_sid'));
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
    session_start();
}
if (PHP_SAPI !== 'cli') {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: SAMEORIGIN');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Cache-Control: no-store');
}

function dbPassword(): string {
    $encoded = settingEnv('DB_PASSWORD_BASE64');
    if ($encoded !== '') {
        $decoded = base64_decode($encoded, true);
        if ($decoded === false) throw new RuntimeException('Senha do banco codificada incorretamente no .env.');
        return $decoded;
    }
    return settingEnv('DB_PASSWORD');
}

function db(): PDO {
    static $pdo;
    if (!$pdo) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            settingEnv('DB_HOST','127.0.0.1'), settingEnv('DB_PORT','3306'), settingEnv('DB_NAME','essenza_beauty'));
        $pdo = new PDO($dsn, settingEnv('DB_USER','root'), dbPassword(), [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]);
        $pdo->exec("SET time_zone = '-03:00'"); // local display dates come from business timezone in PHP
    }
    return $pdo;
}

function sql(string $query, array $params = []): PDOStatement {
    $stmt = db()->prepare($query);
    $stmt->execute($params);
    return $stmt;
}

function salon(): array {
    static $cached;
    if ($cached === null) {
        $cached = sql('SELECT * FROM settings WHERE id=1')->fetch() ?: [];
        if (!empty($cached['timezone'])) {
            date_default_timezone_set($cached['timezone']);
            $offset=(new DateTimeImmutable('now',new DateTimeZone($cached['timezone'])))->format('P');
            db()->exec("SET time_zone = '$offset'");
        }
    }
    return $cached;
}

function e(mixed $text): string { return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
/**
 * Resolve a URL do site a partir da requisição durante o desenvolvimento local.
 *
 * No ServBay é comum acessar por localhost, 127.0.0.1, domínio .test ou
 * uma subpasta /public. Um APP_URL diferente da URL aberta no navegador
 * fazia os CSS, imagens, scripts e chamadas da API apontarem para outro host.
 * Em produção a URL canônica continua sendo a informada em APP_URL.
 */
function appBase(): string {
    $configured = rtrim(settingEnv('APP_URL', 'http://127.0.0.1:8080'), '/');
    if (PHP_SAPI === 'cli' || settingEnv('APP_ENV', 'production') !== 'local'
        || strtolower(settingEnv('APP_AUTO_URL', 'true')) === 'false') {
        return $configured;
    }

    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    // Não propagar cabeçalhos Host arbitrários nas URLs absolutas.
    if (!preg_match('/\A(?:[a-zA-Z0-9.-]+|\[[0-9a-fA-F:]+\])(?::[0-9]{1,5})?\z/D', $host)) {
        return $configured;
    }
    if (str_contains($host, ':') && $host[0] !== '[') {
        $port = (int)substr($host, strrpos($host, ':') + 1);
        if ($port < 1 || $port > 65535) return $configured;
    }

    $https = isset($_SERVER['HTTPS']) && !in_array(strtolower((string)$_SERVER['HTTPS']), ['', 'off', '0'], true);
    $scheme = $https ? 'https' : 'http';
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    // Com o servidor PHP embutido e com Apache/Nginx, SCRIPT_NAME = /index.php.
    // Quando /public é acessado como subpasta, preservamos o prefixo correto.
    $prefix = in_array(basename($script), ['index.php', 'router.php'], true)
        ? rtrim(str_replace('\\', '/', dirname($script)), '/.') : '';
    if ($prefix !== '' && !str_starts_with($prefix, '/')) $prefix = '/'.$prefix;
    if (str_contains($prefix, '..') || str_contains($prefix, '?')) $prefix = '';
    return $scheme . '://' . $host . $prefix;
}
function url(string $path = ''): string { return appBase() . '/' . ltrim($path, '/'); }
function asset(string $path): string { return url('assets/' . ltrim($path,'/')); }
function img(string $image): string {
    if ($image === '') return asset('img/hero.webp');
    if (preg_match('#^https://#', $image)) return $image;
    if (preg_match('#^uploads/[a-z0-9_-]+\.webp$#i', $image)) return asset('img/' . $image);
    return asset('img/' . basename($image));
}
function money(float|int|string $value): string { return 'R$ ' . number_format((float)$value, 2, ',', '.'); }
function csrfToken(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrfCheck(): void {
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');
    if (!is_string($sent) || !hash_equals(csrfToken(), $sent)) fail('Sua sessão expirou. Atualize a página.', 419);
}
function jsonResponse(mixed $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}
function fail(string $message, int $status = 422): never { jsonResponse(['error'=>$message], $status); }
function body(): array {
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) fail('Envie dados JSON válidos.');
    return $data;
}
function requiredString(mixed $input, string $label, int $max = 190): string {
    if (!is_string($input)) fail("Informe $label.");
    $s = trim($input);
    if ($s === '' || mb_strlen($s) > $max) fail("Verifique o campo $label.");
    return $s;
}
function optionalString(mixed $input, int $max = 500): string {
    if ($input === null || $input === '') return '';
    if (!is_string($input) || mb_strlen($input) > $max) fail('Texto inválido ou muito longo.');
    return trim($input);
}
function validEmail(mixed $value, bool $optional = true): ?string {
    $value = trim((string)($value ?? ''));
    if ($value === '' && $optional) return null;
    if (strlen($value) > 190 || !filter_var($value, FILTER_VALIDATE_EMAIL)) fail('Informe um e-mail válido.');
    return mb_strtolower($value);
}
function normalizePhone(mixed $input): string {
    $digits = preg_replace('/\D+/', '', (string)$input);
    if (in_array(strlen($digits), [12,13], true) && str_starts_with($digits,'55')) $digits = substr($digits,2);
    if (!in_array(strlen($digits), [10,11], true) || $digits[0] === '0') fail('Informe um telefone com DDD válido.');
    return $digits;
}
function passHash(string $password): string {
    return password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
}
function passwordOk(mixed $password): string {
    if (!is_string($password) || strlen($password) < 10 || strlen($password) > 128) fail('A senha precisa ter entre 10 e 128 caracteres.');
    return $password;
}
function authClient(): ?array {
    if (empty($_SESSION['client_id'])) return null;
    $client = sql('SELECT id,name,phone,email,created_at FROM clients WHERE id=? AND active=1', [(int)$_SESSION['client_id']])->fetch();
    if (!$client) unset($_SESSION['client_id']);
    return $client ?: null;
}
function requireClient(): array { return authClient() ?? fail('Entre na sua conta para continuar.', 401); }
function authAdmin(): ?array {
    if (empty($_SESSION['admin_id'])) return null;
    $admin = sql('SELECT id,name,email,role FROM admins WHERE id=? AND active=1', [(int)$_SESSION['admin_id']])->fetch();
    if (!$admin) unset($_SESSION['admin_id']);
    return $admin ?: null;
}
function requireAdmin(array $allowedRoles = []): array {
    $admin = authAdmin();
    if (!$admin) fail('Acesso administrativo necessário.',401);
    if ($allowedRoles && !in_array($admin['role'], $allowedRoles, true)) fail('Seu perfil não permite esta ação.',403);
    return $admin;
}
function signIn(string $type, int $id): void {
    session_regenerate_id(true);
    $_SESSION[$type . '_id'] = $id;
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
function signOut(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(),'',time()-42000,$params['path'],$params['domain'],$params['secure'],$params['httponly']);
    }
    session_destroy();
}
function throttle(string $scope, string $identifier): void {
    $key = hash('sha256', $scope . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'cli') . '|' . mb_strtolower($identifier));
    $row = sql('SELECT attempts,(last_attempt > NOW() - INTERVAL 15 MINUTE) recent FROM login_attempts WHERE fingerprint=?',[$key])->fetch();
    if ($row && $row['recent'] && (int)$row['attempts'] >= 6) fail('Muitas tentativas. Tente novamente em 15 minutos.',429);
}
function recordAttempt(string $scope, string $identifier, bool $success): void {
    $key = hash('sha256', $scope . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'cli') . '|' . mb_strtolower($identifier));
    if ($success) { sql('DELETE FROM login_attempts WHERE fingerprint=?',[$key]); return; }
    sql('INSERT INTO login_attempts(fingerprint,attempts,last_attempt) VALUES (?,1,NOW()) ON DUPLICATE KEY UPDATE attempts=IF(last_attempt < NOW()-INTERVAL 15 MINUTE,1,LEAST(attempts+1,255)),last_attempt=NOW()',[$key]);
}
function routePath(): string {
    $path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    $basePath = parse_url(appBase(),PHP_URL_PATH) ?: '';
    if ($basePath && $basePath !== '/' && str_starts_with($path, rtrim($basePath,'/') . '/')) $path = substr($path,strlen(rtrim($basePath,'/')));
    elseif ($basePath && rtrim($path,'/') === rtrim($basePath,'/')) $path='/';
    $path = '/' . ltrim($path,'/');
    // Também aceita abrir /public/index.php diretamente em ambiente local.
    if ($path === '/index.php') return '/';
    return $path;
}
function eventLog(int $appointmentId,string $kind,?int $actorId,string $action,array $details=[]): void {
    sql('INSERT INTO appointment_events(appointment_id,actor_kind,actor_id,action,details) VALUES (?,?,?,?,?)',[
        $appointmentId,$kind,$actorId,$action,json_encode($details,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
}
function validDate(mixed $date): string {
    if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) fail('Data inválida.');
    $d = DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if (!$d || $d->format('Y-m-d') !== $date) fail('Data inválida.');
    return $date;
}
function validClock(mixed $time): string {
    if (!is_string($time) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$time)) fail('Horário inválido.');
    return $time;
}
function assertConfigImage(string $value): string {
    if (strlen($value) > 255 || !preg_match('#^(?:uploads/[a-z0-9_-]+\.webp|[a-z0-9][a-z0-9-]*\.webp|https://[^\s]+)$#i',$value)) fail('Imagem deve ser um arquivo .webp existente ou uma URL HTTPS.');
    return $value;
}
