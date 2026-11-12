<?php
// demo/app/pages/painel.php — visão geral para a tomada de decisão.

declare(strict_types=1);

$classes = allowed_class_ids()
    ? Database::all('SELECT c.*, s.name AS school_name FROM classes c JOIN schools s ON s.id = c.school_id WHERE c.id IN ('.implode(',', allowed_class_ids()).') ORDER BY s.name, c.name')
    : [];
$main = 1; // critério de referência do painel: Estudo
$rows = [];
$tot = ['students' => 0, 'participants' => 0, 'attention' => 0, 'rec' => [], 'recBefore' => [], 'noRec' => 0, 'noRecBefore' => 0];
foreach ($classes as $c) {
    $ov = Sociometry::overview((int) $c['id']);
    if (!$ov['latest']) {
        continue;
    }
    $m = $ov['now'][$main]['metrics'];
    $b = $ov['before'][$main]['metrics'] ?? null;
    $rows[] = ['class' => $c, 'ov' => $ov, 'm' => $m, 'b' => $b];
    $tot['students'] += $m['eligible'];
    $tot['participants'] += $m['participants'];
    $tot['attention'] += count($ov['attention']);
    $tot['rec'][] = $m['reciprocity'];
    $tot['noRec'] += $m['no_received'];
    if ($b) {
        $tot['recBefore'][] = $b['reciprocity'];
        $tot['noRecBefore'] += $b['no_received'];
    }
}
$avgRec = $tot['rec'] ? array_sum($tot['rec']) / count($tot['rec']) : 0;
$avgRecBefore = $tot['recBefore'] ? array_sum($tot['recBefore']) / count($tot['recBefore']) : $avgRec;
$openInterventions = (int) Database::value("SELECT COUNT(*) FROM interventions WHERE status <> 'concluida' AND class_id IN (".(implode(',', allowed_class_ids()) ?: '0').')');

page_start('Painel', 'painel');
page_head('Painel', 'Situação das turmas na aplicação mais recente, critério Estudo, comparada à anterior.',
    '<a class="btn" href="'.e(url('relatorio', ['tipo' => 'institucional'])).'">Relatório institucional</a>');
?>
<div class="stats">
  <?= kpi('Alunos acompanhados', (string) $tot['students'], count($rows).' turmas') ?>
  <?= kpi('Participação', $tot['students'] ? pct($tot['participants'] / $tot['students']) : '—', $tot['participants'].' responderam') ?>
  <?= kpi('Reciprocidade média', pct($avgRec), 'escolhas correspondidas', delta_badge($avgRec, $avgRecBefore)) ?>
  <?= kpi('Sem indicações recebidas', (string) $tot['noRec'], 'no critério Estudo', delta_badge($tot['noRec'], $tot['noRecBefore'], false, false)) ?>
  <?= kpi('Pontos de atenção', (string) $tot['attention'], 'alunos para observar') ?>
  <?= kpi('Intervenções ativas', (string) $openInterventions, 'planejadas ou em andamento') ?>
</div>

<div class="card">
  <h2>Turmas</h2>
  <p class="muted small">Reciprocidade: parte das escolhas que foi correspondida. Coesão: pares recíprocos sobre os pares possíveis. Passe o mouse nas barras para ver os valores.</p>
  <div class="table-wrap"><table>
    <thead><tr><th>Turma</th><th>Última aplicação</th><th class="num">Alunos</th><th style="width:19%">Participação</th><th style="width:19%">Reciprocidade</th><th>Variação</th><th class="num">Sem indicações</th><th class="num">Atenção</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $c = $r['class']; $m = $r['m']; ?>
      <tr>
        <td><a href="<?= e(url('turma', ['id' => $c['id']])) ?>"><strong><?= e($c['name']) ?></strong></a><br><span class="muted small" style="white-space:nowrap"><?= e($c['school_name']) ?></span></td>
        <td class="small"><?= e(date_br($r['ov']['latest']['applied_on'])) ?></td>
        <td class="num"><?= $m['eligible'] ?></td>
        <td><div class="barcell"><?= bar($m['participation'], 's3', $m['participants'].' de '.$m['eligible'].' responderam') ?><b><?= pct($m['participation']) ?></b></div></td>
        <td><div class="barcell"><?= bar($m['reciprocity'], 's1', $m['mutual_pairs'].' pares recíprocos em '.$m['choices'].' escolhas') ?><b><?= pct($m['reciprocity']) ?></b></div></td>
        <td><?= $r['b'] ? delta_badge($m['reciprocity'], $r['b']['reciprocity']) : '<span class="muted small">1ª aplicação</span>' ?></td>
        <td class="num"><?= $m['no_received'] ?></td>
        <td class="num"><?= count($r['ov']['attention']) ?></td>
        <td><a class="btn sm" href="<?= e(url('turma', ['id' => $c['id']])) ?>">Abrir</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div class="grid g2">
  <div class="card">
    <h2>Onde olhar primeiro</h2>
    <p class="muted small">Alunos com mais sinais combinados na última aplicação. A lista orienta a observação; não classifica o aluno.</p>
    <table>
      <thead><tr><th>Aluno</th><th>Turma</th><th>Motivos registrados</th></tr></thead>
      <tbody>
      <?php
      $top = [];
      foreach ($rows as $r) {
          foreach (array_slice($r['ov']['attention'], 0, 3) as $it) {
              $top[] = $it + ['class' => $r['class']];
          }
      }
      usort($top, fn ($a, $b) => $b['score'] <=> $a['score']);
      foreach (array_slice($top, 0, 7) as $it): ?>
        <tr>
          <td style="white-space:nowrap"><a href="<?= e(url('aluno', ['id' => $it['student']['id']])) ?>"><?= e($it['student']['name']) ?></a></td>
          <td class="small" style="white-space:nowrap"><?= e($it['class']['name']) ?></td>
          <td class="small muted"><?= e(implode(' · ', array_slice($it['reasons'], 0, 2))) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p style="margin-top:12px"><a class="btn sm" href="<?= e(url('relatorios')) ?>">Ver relatórios de atenção pedagógica</a></p>
  </div>
  <div class="card">
    <h2>Evolução entre aplicações</h2>
    <p class="muted small">Alunos sem indicações recebidas no critério Estudo: aplicação anterior → mais recente.</p>
    <table>
      <thead><tr><th>Turma</th><th class="num">Antes</th><th class="num">Agora</th><th>Variação</th><th>Intervenções</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): if (!$r['b']) { continue; }
          $n = (int) Database::value('SELECT COUNT(*) FROM interventions WHERE class_id = ?', [$r['class']['id']]); ?>
        <tr>
          <td><a href="<?= e(url('comparar', ['id' => $r['class']['id']])) ?>"><?= e($r['class']['name']) ?></a></td>
          <td class="num"><?= $r['b']['no_received'] ?></td>
          <td class="num"><strong><?= $r['m']['no_received'] ?></strong></td>
          <td><?= delta_badge($r['m']['no_received'], $r['b']['no_received'], false, false) ?></td>
          <td class="small"><?= $n ? $n.' registrada(s)' : '<span class="muted">nenhuma</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php page_end();
