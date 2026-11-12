<?php
// demo/public/index.php — front controller: única porta de entrada da aplicação.

declare(strict_types=1);

require dirname(__DIR__).'/app/config.php';
require dirname(__DIR__).'/app/Database.php';
require dirname(__DIR__).'/app/helpers.php';
require dirname(__DIR__).'/app/Sociometry.php';
require dirname(__DIR__).'/app/view.php';

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
]);
session_name('socdemo');
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store, private');

// Páginas existentes (lista fechada: o parâmetro nunca vira caminho de arquivo livre).
$pages = [
    'login' => false, 'responder' => false,            // públicas
    'sair' => true, 'painel' => true, 'turmas' => true, 'turma' => true, 'aluno' => true,
    'comparar' => true, 'grupos' => true, 'relatorios' => true, 'relatorio' => true,
    'exportar' => true, 'intervencoes' => true, 'aplicacao' => true, 'metodologia' => true,
];
$page = (string) ($_GET['p'] ?? (current_user() ? 'painel' : 'login'));
if (!array_key_exists($page, $pages)) {
    not_found();
}
if ($pages[$page]) {
    require_login();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
}

try {
    require dirname(__DIR__)."/app/pages/{$page}.php";
} catch (Throwable $ex) {
    error_log('[sociometria-demo] '.$ex->getMessage().' em '.$ex->getFile().':'.$ex->getLine());
    http_response_code(500);
    echo APP_DEBUG ? '<pre>'.e((string) $ex).'</pre>' : 'Ocorreu um erro inesperado. Tente novamente em instantes.';
}
