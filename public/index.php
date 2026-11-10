<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/core.php';
require dirname(__DIR__).'/src/setup.php';
setupGate();
require dirname(__DIR__).'/src/booking.php';
require dirname(__DIR__).'/src/api.php';
require dirname(__DIR__).'/src/admin_api.php';
require dirname(__DIR__).'/src/views/layout.php';
$path=routePath();$method=$_SERVER['REQUEST_METHOD']??'GET';
try {
    salon(); // sets the configured business-local timezone for every request
    if(str_starts_with($path,'/api/'))apiHandle($path,$method);
    if($method!=='GET'){http_response_code(405);exit('Método não permitido.');}
    switch(rtrim($path,'/')?:'/'){
        case '/': require dirname(__DIR__).'/src/views/home.php';break;
        case '/agendar': require dirname(__DIR__).'/src/views/booking.php';break;
        case '/entrar': if(authClient()){header('Location: '.url('minha-conta'),true,302);exit;}require dirname(__DIR__).'/src/views/auth.php';break;
        case '/minha-conta': if(!authClient()){header('Location: '.url('entrar?return=minha-conta'),true,302);exit;} require dirname(__DIR__).'/src/views/account.php';break;
        case '/confirmacao':require dirname(__DIR__).'/src/views/confirmation.php';break;
        case '/privacidade':require dirname(__DIR__).'/src/views/privacy.php';break;
        case '/admin/login':if(authAdmin()){header('Location: '.url('admin'),true,302);exit;}require dirname(__DIR__).'/src/views/admin_login.php';break;
        case '/admin':if(!authAdmin()){header('Location: '.url('admin/login'),true,302);exit;}require dirname(__DIR__).'/src/views/admin.php';break;
        default:http_response_code(404);pageStart('Página não encontrada');echo '<section class="section container"><h1>Página não encontrada</h1><a class="btn btn-primary" href="'.e(url()).'">Voltar ao início</a></section>';pageEnd();
    }
} catch(BookingError $e) { fail($e->getMessage(),409); }
  catch(PDOException $e) {
    error_log('[Essenza DB] '.$e->getMessage());
    if(($e->errorInfo[1]??null)===1062)fail('Registro já existente. Confira os dados informados.',409);
    if(str_starts_with($path,'/api/'))fail('Não foi possível processar sua solicitação. Tente novamente.',500);
    http_response_code(503);echo '<h1>Banco de dados indisponível</h1><p>Verifique o arquivo .env e a instalação do MySQL. Consulte README.md.</p>';
  } catch(Throwable $e) {
    error_log('[Essenza] '.$e->getMessage());
    if(settingEnv('APP_DEBUG')==='true')error_log($e->getTraceAsString());
    if(str_starts_with($path,'/api/'))fail('Ocorreu um erro inesperado. Tente novamente.',500);
    http_response_code(500);echo '<h1>Erro ao carregar a página.</h1><p>Consulte o registro de erros do PHP.</p>';
  }
