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

/** Tela de erro de instalação: diz o que conferir, sem revelar senha nem detalhes internos. */
function setup_error(string $title, string $what): never
{
    http_response_code(503);
    echo '<!DOCTYPE html><html lang="pt-BR"><meta charset="utf-8"><meta name="robots" content="noindex"><title>Configuração pendente</title>'
        .'<body style="font:16px/1.5 system-ui,sans-serif;max-width:640px;margin:60px auto;padding:0 20px;color:#14181f">'
        .'<h1 style="font-size:22px">'.e($title).'</h1><p>'.$what.'</p>'
        .'<p style="color:#7b8292;font-size:14px">Depois de corrigir, recarregue esta página.</p></body></html>';
    exit;
}

if (!is_readable(dirname(__DIR__).'/.env')) {
    setup_error('Arquivo .env não encontrado', 'Na pasta <code>demo</code> (a mesma que contém <code>app</code>, <code>public</code> e <code>sql</code>), '
        .'copie o arquivo <code>.env.example</code> com o nome <code>.env</code> e preencha os dados do banco. '
        .'No Gerenciador de Arquivos, ative <em>Configurações → Mostrar arquivos ocultos</em> para enxergá-lo.');
}

try {
    require dirname(__DIR__)."/app/pages/{$page}.php";
} catch (PDOException $ex) {
    error_log('[sociometria-demo] banco: '.$ex->getMessage());
    $code = (int) ($ex->errorInfo[1] ?? $ex->getCode());
    $hint = match ($code) {
        1045 => 'O banco recusou o <strong>usuário ou a senha</strong>. Confira <code>DB_USER</code> (com o prefixo da conta, ex.: <code>itthri79_...</code>) e <code>DB_PASS</code> no arquivo <code>.env</code>. Se tiver dúvida, redefina a senha do usuário em <em>Bancos de dados MySQL</em> e copie-a de novo.',
        1044 => 'O usuário existe, mas <strong>não tem permissão neste banco</strong>. No cPanel, em <em>Bancos de dados MySQL → Adicionar usuário ao banco de dados</em>, vincule o usuário ao banco com SELECT, INSERT, UPDATE e DELETE.',
        1049 => 'O <strong>nome do banco</strong> em <code>DB_NAME</code> não existe. Use o nome completo, com o prefixo da conta (ex.: <code>itthri79_socmet_demo</code>).',
        2002, 2005 => 'Não foi possível alcançar o servidor de banco. Em hospedagem cPanel, <code>DB_HOST</code> deve ser <code>localhost</code>.',
        1146 => 'A conexão funcionou, mas <strong>faltam tabelas</strong>. Importe <code>sql/schema.sql</code> e depois <code>sql/dados_demo.sql</code> pelo phpMyAdmin.',
        default => 'Falha ao acessar o banco de dados (código '.$code.'). Confira os quatro campos <code>DB_HOST</code>, <code>DB_NAME</code>, <code>DB_USER</code> e <code>DB_PASS</code> no arquivo <code>.env</code>.',
    };
    setup_error('Não foi possível usar o banco de dados', $hint);
} catch (Throwable $ex) {
    error_log('[sociometria-demo] '.$ex->getMessage().' em '.$ex->getFile().':'.$ex->getLine());
    http_response_code(500);
    echo APP_DEBUG ? '<pre>'.e((string) $ex).'</pre>' : 'Ocorreu um erro inesperado. Tente novamente em instantes.';
}
