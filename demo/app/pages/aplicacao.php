<?php
// demo/app/pages/aplicacao.php — acompanhamento de uma aplicação em andamento e códigos de acesso.

declare(strict_types=1);

$app = Database::one('SELECT * FROM applications WHERE id = ?', [(int) ($_GET['id'] ?? 0)]);
if (!$app) {
    not_found();
}
$class = load_class((int) $app['class_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $app['status'] === 'open') {
    Database::run("UPDATE applications SET status = 'closed' WHERE id = ?", [$app['id']]);
    audit('aplicacao.encerrada', 'aplicação '.$app['id']);
    flash('Aplicação encerrada. Os resultados já estão disponíveis.');
    redirect('turma', ['id' => $class['id'], 'app' => $app['id']]);
}

$parts = Database::all('SELECT p.*, s.name, s.call_number FROM participations p JOIN students s ON s.id = p.student_id WHERE p.application_id = ? ORDER BY s.call_number', [$app['id']]);
$done = count(array_filter($parts, fn ($p) => $p['answered_at'] !== null));
$print = isset($_GET['imprimir']);
page_start($app['title'], 'turmas', $print);
page_head($app['title'], $class['name'].' · '.$class['school_name'].' · '.date_br($app['applied_on']),
    $app['status'] === 'open' ? '<a class="btn" href="'.e(url('aplicacao', ['id' => $app['id'], 'imprimir' => 1])).'">Imprimir códigos</a>' : '',
    ['Turmas' => url('turmas'), $class['name'] => url('turma', ['id' => $class['id']])]);
if ($print) {
    echo '<p class="no-print"><button class="btn primary" data-print>Imprimir / salvar em PDF</button></p>';
}
?>
<div class="stats">
  <?= kpi('Respostas recebidas', $done.' de '.count($parts), pct(count($parts) ? $done / count($parts) : 0)) ?>
  <?= kpi('Faltam responder', (string) (count($parts) - $done)) ?>
  <?= kpi('Situação', $app['status'] === 'open' ? 'Aberta' : 'Encerrada') ?>
</div>
<?php if ($app['status'] === 'open'): ?>
<div class="card">
  <h2>Códigos de acesso dos alunos</h2>
  <p class="muted small">Cada aluno recebe um código individual, válido só para esta aplicação e de uso único. O aluno entra em <strong><?= e((APP_URL ?: '').'/'.url('responder')) ?></strong> e digita o código. Recorte e entregue individualmente.</p>
  <div class="codes">
  <?php foreach ($parts as $p): if ($p['answered_at'] !== null) { continue; } ?>
    <div><span class="small muted">nº <?= (int) $p['call_number'] ?> · <?= e($p['name']) ?></span><code><?= e($p['access_code']) ?></code></div>
  <?php endforeach; ?>
  </div>
</div>
<div class="card no-print">
  <h2>Quem já respondeu</h2>
  <div class="chips"><?php foreach ($parts as $p): if ($p['answered_at'] === null) { continue; } ?><span class="chip"><?= e($p['name']) ?></span><?php endforeach; ?></div>
  <form method="post" action="<?= e(url('aplicacao', ['id' => $app['id']])) ?>" style="margin-top:18px" onsubmit="return confirm('Encerrar a aplicação? Os alunos que faltam não poderão mais responder.')">
    <?= csrf_field() ?><button class="btn primary">Encerrar aplicação e gerar resultados</button>
  </form>
</div>
<?php else: ?>
<div class="card"><p>Aplicação encerrada. <a href="<?= e(url('turma', ['id' => $class['id'], 'app' => $app['id']])) ?>">Ver resultados</a></p></div>
<?php endif;
page_end();
