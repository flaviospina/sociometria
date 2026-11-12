<?php
// demo/app/helpers.php — funções de apoio: saída segura, sessão, CSRF, autorização.

declare(strict_types=1);

function e(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $page = 'painel', array $params = []): string
{
    return 'index.php?'.http_build_query(['p' => $page] + $params);
}

function redirect(string $page, array $params = []): never
{
    header('Location: '.url($page, $params));
    exit;
}

function csrf_token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="'.e(csrf_token()).'">';
}

function csrf_check(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(419);
        exit('Sessão expirada. Volte e tente novamente.');
    }
}

function flash(?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['flash'] = $message;

        return null;
    }
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return $m;
}

/** @return array{id:int,name:string,email:string,role:string}|null */
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): array
{
    $u = current_user();
    if ($u === null) {
        redirect('login');
    }

    return $u;
}

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'admin';
}

/** IDs das turmas que o usuário logado pode acessar. */
function allowed_class_ids(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $u = require_login();
    $rows = is_admin()
        ? Database::all('SELECT id FROM classes')
        : Database::all('SELECT class_id AS id FROM user_classes WHERE user_id = ?', [$u['id']]);

    return $cache = array_map('intval', array_column($rows, 'id'));
}

/** Carrega a turma ou encerra com 404 (mesma resposta para "não existe" e "sem permissão"). */
function load_class(int $id): array
{
    $class = in_array($id, allowed_class_ids(), true)
        ? Database::one('SELECT c.*, s.name AS school_name FROM classes c JOIN schools s ON s.id = c.school_id WHERE c.id = ?', [$id])
        : null;
    if ($class === null) {
        not_found();
    }

    return $class;
}

function not_found(): never
{
    http_response_code(404);
    exit('Página não encontrada.');
}

function audit(string $action, ?string $detail = null): void
{
    Database::run(
        'INSERT INTO audit_log (user_id, action, detail, ip, created_at) VALUES (?, ?, ?, ?, NOW())',
        [current_user()['id'] ?? null, $action, $detail, $_SERVER['REMOTE_ADDR'] ?? null],
    );
}

function date_br(?string $ymd): string
{
    return $ymd ? date('d/m/Y', strtotime($ymd)) : '—';
}

function pct(float $v, int $dec = 0): string
{
    return number_format($v * 100, $dec, ',', '.').'%';
}

function num(float $v, int $dec = 1): string
{
    return number_format($v, $dec, ',', '.');
}

/** Iniciais para os nós do sociograma: "Ana Lima" => "AL". */
function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));

    return mb_strtoupper(mb_substr($parts[0], 0, 1).mb_substr(end($parts), 0, 1));
}

/** Variação entre duas medições, com seta e texto (nunca só cor). */
function delta_badge(float $now, float $before, bool $higherIsBetter = true, bool $isPct = true): string
{
    $d = $now - $before;
    if (abs($d) < 0.0005) {
        return '<span class="delta flat">= estável</span>';
    }
    $good = ($d > 0) === $higherIsBetter;
    $txt = ($d > 0 ? '▲ +' : '▼ −').($isPct ? num(abs($d) * 100, 1).' p.p.' : num(abs($d), abs($d) == floor(abs($d)) ? 0 : 1));

    return '<span class="delta '.($good ? 'up' : 'down').'">'.$txt.'</span>';
}
