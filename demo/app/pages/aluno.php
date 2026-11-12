<?php
// demo/app/pages/aluno.php — ficha individual (acesso restrito e registrado).

declare(strict_types=1);

$student = Database::one('SELECT * FROM students WHERE id = ?', [(int) ($_GET['id'] ?? 0)]);
if (!$student) {
    not_found();
}
$class = load_class((int) $student['class_id']);   // 404 se a turma não for do usuário
$sid = (int) $student['id'];
$apps = Sociometry::applications((int) $class['id'], true);
$criteria = Sociometry::criteria();
$print = isset($_GET['imprimir']);
audit('aluno.ficha', 'aluno '.$sid);

$history = [];   // [appId][critId] => dados do aluno
foreach ($apps as $a) {
    foreach ($criteria as $cid => $_) {
        $an = Sociometry::analyze((int) $a['id'], $cid);
        $history[$a['id']][$cid] = ['me' => $an['students'][$sid] ?? null, 'all' => $an['students'], 'metrics' => $an['metrics']];
    }
}
$last = end($apps);
$names = fn (array $ids, array $all) => array_map(fn ($i) => $all[$i]['name'], $ids);
$interventions = Database::all('SELECT * FROM interventions WHERE class_id = ? AND (student_id = ? OR student_id IS NULL) ORDER BY created_at DESC', [$class['id'], $sid]);

page_start($student['name'], 'turmas', $print);
page_head($student['name'], $class['name'].' · '.$class['school_name'].' · nº '.$student['call_number'],
    '<a class="btn primary" href="'.e(url('aluno', ['id' => $sid, 'imprimir' => 1])).'">Relatório individual</a>',
    ['Turmas' => url('turmas'), $class['name'] => url('turma', ['id' => $class['id']])]);
if ($print): ?>
  <p class="no-print"><button class="btn primary" data-print>Imprimir / salvar em PDF</button></p>
  <div class="report-meta" style="margin-bottom:12px"><span class="conf">Confidencial — uso pedagógico restrito</span><span>Gerado em <?= date('d/m/Y H:i') ?> por <?= e(current_user()['name']) ?></span></div>
<?php endif;
echo method_note();

if (!$last) {
    echo '<div class="card">Sem aplicações encerradas.</div>';
    page_end();

    return;
}
?>
<div class="card">
  <h2>Última aplicação — <?= e(date_br($last['applied_on'])) ?></h2>
  <?php if (!($history[$last['id']][1]['me']['participated'] ?? false)): ?>
    <p class="muted">O aluno não respondeu a esta aplicação: há dados do que os colegas indicaram, mas não das escolhas dele.</p>
  <?php endif; ?>
  <div class="grid g3" style="margin-top:12px">
  <?php foreach ($criteria as $cid => $c): $h = $history[$last['id']][$cid]; $me = $h['me'];
      $neg = (int) $c['polarity'] < 0; ?>
    <div>
      <h3><?= e($c['label']) ?></h3>
      <p class="small muted">“<?= e($c['question']) ?>”</p>
      <table>
        <tr><td>Indicações recebidas</td><td class="num"><strong><?= $me['received'] ?></strong> <span class="muted small">(média da turma <?= num($h['metrics']['mean_received']) ?>)</span></td></tr>
        <tr><td>Escolhas feitas</td><td class="num"><?= $me['participated'] ? $me['given'] : '—' ?></td></tr>
        <tr><td>Recíprocas</td><td class="num"><?= $me['mutual'] ?></td></tr>
        <?php if (!$neg): ?><tr><td>Faixa</td><td class="num"><?= band_badge($me['band']) ?></td></tr><?php endif; ?>
      </table>
      <?php if (!$neg): ?>
        <p class="small" style="margin:10px 0 4px"><strong>Escolheu</strong></p>
        <div class="chips"><?php foreach ($me['chose'] as $i): ?><span class="chip<?= in_array($i, $me['mutual_with'], true) ? ' mutual' : '' ?>"><?= e($h['all'][$i]['name']) ?><?= in_array($i, $me['mutual_with'], true) ? ' ⇄' : '' ?></span><?php endforeach; ?><?= $me['chose'] ? '' : '<span class="muted small">—</span>' ?></div>
        <p class="small" style="margin:10px 0 4px"><strong>Foi escolhido por</strong></p>
        <div class="chips"><?php foreach ($me['chosen_by'] as $i): ?><span class="chip<?= in_array($i, $me['mutual_with'], true) ? ' mutual' : '' ?>"><?= e($h['all'][$i]['name']) ?></span><?php endforeach; ?><?= $me['chosen_by'] ? '' : '<span class="muted small">ninguém neste critério</span>' ?></div>
      <?php else: ?>
        <p class="small muted" style="margin-top:10px">Por cuidado com os alunos, os nomes deste critério não são listados na ficha; use a matriz da turma, com acesso de professor.</p>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <h2>Evolução</h2>
  <p class="muted small">Indicações recebidas e escolhas recíprocas em cada aplicação.</p>
  <table>
    <thead><tr><th>Aplicação</th><?php foreach ($criteria as $c): ?><th class="num"><?= e($c['label']) ?> — recebidas</th><th class="num">recíprocas</th><?php endforeach; ?><th>Participou</th></tr></thead>
    <tbody>
    <?php foreach ($apps as $a): ?>
      <tr><td><?= e($a['title']) ?> <span class="muted small">(<?= e(date_br($a['applied_on'])) ?>)</span></td>
        <?php foreach ($criteria as $cid => $c): $me = $history[$a['id']][$cid]['me']; ?>
          <td class="num"><strong><?= $me['received'] ?? '—' ?></strong></td><td class="num"><?= $me['mutual'] ?? '—' ?></td>
        <?php endforeach; ?>
        <td class="small"><?= ($history[$a['id']][1]['me']['participated'] ?? false) ? 'Sim' : 'Não' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h2>Intervenções relacionadas</h2>
  <?php if ($interventions): ?>
  <table><thead><tr><th>Ação</th><th>Alcance</th><th>Situação</th><th>Prazo</th></tr></thead><tbody>
  <?php foreach ($interventions as $i): ?>
    <tr><td><strong><?= e($i['title']) ?></strong><br><span class="small muted"><?= e($i['description']) ?></span></td>
      <td class="small"><?= $i['student_id'] ? 'Individual' : 'Turma' ?></td>
      <td><span class="tag <?= e($i['status']) ?>"><?= e(str_replace('_', ' ', $i['status'])) ?></span></td>
      <td class="small"><?= e(date_br($i['due_on'])) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php else: ?><p class="muted">Nenhuma intervenção registrada.</p><?php endif; ?>
  <p class="no-print" style="margin-top:12px"><a class="btn sm" href="<?= e(url('intervencoes', ['turma' => $class['id'], 'aluno' => $sid])) ?>#nova">Registrar intervenção para este aluno</a></p>
</div>
<?php if ($print): ?><div class="report-foot">Documento de uso interno da equipe pedagógica. Dados fictícios de demonstração. Interpretação sujeita à revisão do professor responsável.</div><?php endif;
page_end();
