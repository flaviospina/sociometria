<?php
// demo/app/pages/responder.php — formulário do ALUNO (público, com código individual de uso único).
// O aluno nunca vê escolhas de colegas nem resultados.

declare(strict_types=1);

$find = fn (string $code) => Database::one(
    "SELECT p.*, s.name, s.class_id, a.title FROM participations p
       JOIN students s ON s.id = p.student_id JOIN applications a ON a.id = p.application_id
      WHERE p.access_code = ? AND a.status = 'open' AND p.answered_at IS NULL",
    [$code],
);
$code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['code'] ?? '')));
$error = null;
$done = false;
$part = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['code_tries'] = ($_SESSION['code_tries'] ?? 0) + 1;
    $part = $_SESSION['code_tries'] <= 15 && strlen($code) === 6 ? $find($code) : null;
    if (!$part) {
        $error = 'Código inválido ou já utilizado. Confira com a professora ou o professor.';
    } elseif (isset($_POST['enviar'])) {
        $mates = array_map('intval', array_column(Database::all('SELECT id FROM students WHERE class_id = ? AND id <> ?', [$part['class_id'], $part['student_id']]), 'id'));
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        foreach (Sociometry::criteria() as $cid => $c) {
            // Só aceita colegas da própria turma, sem repetição e dentro do limite da pergunta.
            $picked = array_slice(array_values(array_unique(array_intersect(array_map('intval', (array) ($_POST['c'][$cid] ?? [])), $mates))), 0, (int) $c['max_choices']);
            foreach ($picked as $rank => $chosen) {
                Database::run('INSERT INTO choices (application_id, criterion_id, chooser_id, chosen_id, choice_rank) VALUES (?, ?, ?, ?, ?)', [$part['application_id'], $cid, $part['student_id'], $chosen, $rank + 1]);
            }
        }
        Database::run('UPDATE participations SET answered_at = NOW() WHERE application_id = ? AND student_id = ? AND answered_at IS NULL', [$part['application_id'], $part['student_id']]);
        $pdo->commit();
        unset($_SESSION['code_tries']);
        $done = true;
    }
}

page_start('Responder ao questionário');
?>
<div style="max-width:820px;margin:0 auto">
<?php if ($done): ?>
  <div class="card" style="text-align:center;padding:40px 20px">
    <h1>Obrigado!</h1>
    <p class="muted" style="margin-top:8px">Suas respostas foram registradas. Só a equipe pedagógica tem acesso a elas.</p>
    <p style="margin-top:18px"><a class="btn" href="<?= e(url('responder')) ?>">Próximo aluno</a></p>
  </div>
<?php elseif ($part): $mates = Database::all('SELECT id, name FROM students WHERE class_id = ? AND id <> ? ORDER BY name', [$part['class_id'], $part['student_id']]); ?>
  <div class="card">
    <h1>Olá, <?= e(explode(' ', $part['name'])[0]) ?>!</h1>
    <p class="muted" style="margin-top:6px">Não existe resposta certa ou errada. Ninguém da turma verá o que você marcou.</p>
  </div>
  <form method="post" action="<?= e(url('responder')) ?>">
    <?= csrf_field() ?><input type="hidden" name="code" value="<?= e($code) ?>">
    <?php foreach (Sociometry::criteria() as $cid => $c): ?>
    <div class="card">
      <h2><?= e($c['question']) ?></h2>
      <p class="muted small">Escolha até <?= (int) $c['max_choices'] ?> colegas<?= (int) $c['polarity'] < 0 ? ' — ou deixe em branco, se preferir' : '' ?>.</p>
      <div class="mates" data-max="<?= (int) $c['max_choices'] ?>">
        <?php foreach ($mates as $m): ?><label><input type="checkbox" name="c[<?= $cid ?>][]" value="<?= (int) $m['id'] ?>"> <?= e($m['name']) ?></label><?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <p><button class="btn primary" name="enviar" value="1" style="padding:13px 26px;font-size:16px">Enviar minhas respostas</button></p>
  </form>
<?php else: ?>
  <div class="center-wrap" style="min-height:60vh">
    <div class="card login-card">
      <h1 style="font-size:22px">Questionário da turma</h1>
      <p class="muted" style="margin-top:6px">Digite o código que você recebeu.</p>
      <?php if ($error): ?><div class="err"><?= e($error) ?></div><?php endif; ?>
      <form method="post" action="<?= e(url('responder')) ?>" autocomplete="off">
        <?= csrf_field() ?>
        <label for="code">Código de acesso</label>
        <input type="text" id="code" name="code" maxlength="6" required autofocus style="font-size:24px;letter-spacing:.2em;text-align:center;text-transform:uppercase">
        <p style="margin-top:16px"><button class="btn primary" style="width:100%;justify-content:center">Continuar</button></p>
      </form>
      <p class="small" style="margin-top:14px"><a href="<?= e(url('login')) ?>">Sou da equipe pedagógica</a></p>
    </div>
  </div>
<?php endif; ?>
</div>
<?php page_end();
