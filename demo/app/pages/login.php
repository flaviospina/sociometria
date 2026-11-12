<?php
// demo/app/pages/login.php

declare(strict_types=1);

if (current_user()) {
    redirect('painel');
}
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Limite simples de tentativas por sessão (a versão completa limita por conta e IP).
    $_SESSION['login_tries'] = ($_SESSION['login_tries'] ?? 0) + 1;
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $user = $_SESSION['login_tries'] <= 8 ? Database::one('SELECT * FROM users WHERE email = ?', [$email]) : null;
    if ($user && password_verify((string) ($_POST['password'] ?? ''), $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => (int) $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'role' => $user['role']];
        unset($_SESSION['login_tries']);
        audit('login');
        redirect('painel');
    }
    $error = $_SESSION['login_tries'] > 8 ? 'Muitas tentativas. Feche o navegador e tente mais tarde.' : 'E-mail ou senha inválidos.';
}
page_start('Entrar');
?>
<div class="center-wrap">
  <div class="card login-card">
    <div class="brand-lg">
      <svg viewBox="0 0 40 40" width="40" height="40" aria-hidden="true"><circle cx="20" cy="20" r="19" fill="#2a78d6"/><g stroke="#fff" stroke-width="1.6"><line x1="12" y1="14" x2="27" y2="12"/><line x1="12" y1="14" x2="20" y2="27"/><line x1="27" y1="12" x2="20" y2="27"/><line x1="27" y1="12" x2="30" y2="24"/></g><g fill="#fff"><circle cx="12" cy="14" r="3.4"/><circle cx="27" cy="12" r="3.4"/><circle cx="20" cy="27" r="3.4"/><circle cx="30" cy="24" r="2.6"/></g></svg>
      Sociometria Escolar
    </div>
    <p class="muted">Entenda as relações da turma e decida com base em dados.</p>
    <?php if ($error): ?><div class="err"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="<?= e(url('login')) ?>" autocomplete="off">
      <?= csrf_field() ?>
      <label for="email">E-mail</label>
      <input type="email" id="email" name="email" required value="<?= e($_POST['email'] ?? 'gestao@demo.sociometria') ?>">
      <label for="password">Senha</label>
      <input type="password" id="password" name="password" required>
      <p style="margin-top:16px"><button class="btn primary" style="width:100%;justify-content:center">Entrar</button></p>
    </form>
    <div class="hintbox">
      <strong>Acessos de demonstração</strong> (senha <code>demo1234</code>)<br>
      Gestão: <code>gestao@demo.sociometria</code><br>
      Docente (uma turma): <code>professora@demo.sociometria</code>
    </div>
    <p class="small" style="margin-top:14px">É aluno? <a href="<?= e(url('responder')) ?>">Responder ao questionário com o código</a></p>
  </div>
</div>
<?php page_end();
