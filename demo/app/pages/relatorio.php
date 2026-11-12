<?php
// demo/app/pages/relatorio.php — relatórios para impressão/PDF: turma, atenção pedagógica e institucional.

declare(strict_types=1);

$type = $_GET['tipo'] ?? '';
if (!in_array($type, ['turma', 'atencao', 'institucional'], true)) {
    not_found();
}
$criteria = Sociometry::criteria();
$reportId = strtoupper(substr($type, 0, 3)).'-'.date('Ymd-His').'-'.current_user()['id'];

$head = function (string $title, string $scope) use ($reportId): void {
    echo '<p class="no-print"><button class="btn primary" data-print>Imprimir / salvar em PDF</button> <a class="btn" href="'.e(url('relatorios')).'">Voltar aos relatórios</a></p>';
    echo '<div class="report-head"><span class="conf">Confidencial — uso pedagógico restrito</span><h1 style="margin-top:8px">'.e($title).'</h1>'
        .'<div class="report-meta"><span>'.e($scope).'</span><span>Relatório nº '.e($reportId).'</span><span>Gerado em '.date('d/m/Y H:i').'</span><span>Por '.e(current_user()['name']).'</span></div></div>';
};
$foot = '<div class="report-foot">Dados fictícios de demonstração. Os indicadores descrevem as escolhas registradas no questionário e não constituem diagnóstico. '
    .'A interpretação deve ser revisada pelo professor responsável antes de qualquer decisão.</div>';

// ------------------------------------------------------------------ institucional
if ($type === 'institucional') {
    $ids = allowed_class_ids();
    $classes = $ids ? Database::all('SELECT c.*, s.name AS school_name FROM classes c JOIN schools s ON s.id = c.school_id WHERE c.id IN ('.implode(',', $ids).') ORDER BY s.name, c.name') : [];
    audit('relatorio.institucional', $reportId);
    page_start('Relatório institucional', 'relatorios', true);
    $head('Relatório institucional', count($classes).' turmas · última aplicação de cada uma');
    foreach ($criteria as $cid => $crit): $neg = (int) $crit['polarity'] < 0; ?>
    <div class="card">
      <h2><?= e($crit['label']) ?></h2>
      <p class="muted small">“<?= e($crit['question']) ?>”</p>
      <table>
        <thead><tr><th>Turma</th><th class="num">Alunos</th><th class="num">Participação</th><th style="width:20%">Reciprocidade</th><th>Variação</th><th class="num">Coesão</th><th class="num"><?= $neg ? 'Pares em dificuldade' : 'Sem indicações' ?></th><th class="num">Sem recíproca</th></tr></thead>
        <tbody>
        <?php foreach ($classes as $c): $ov = Sociometry::overview((int) $c['id']);
            if (!$ov['latest']) { continue; } $m = $ov['now'][$cid]['metrics']; $b = $ov['before'][$cid]['metrics'] ?? null; ?>
          <tr><td><strong><?= e($c['name']) ?></strong> <span class="small muted"><?= e($c['school_name']) ?></span></td>
            <td class="num"><?= $m['eligible'] ?></td><td class="num"><?= pct($m['participation']) ?></td>
            <td><div class="barcell"><?= bar($m['reciprocity'], $neg ? 's2' : 's1') ?><b><?= pct($m['reciprocity']) ?></b></div></td>
            <td><?= $b ? delta_badge($m['reciprocity'], $b['reciprocity'], !$neg) : '—' ?></td>
            <td class="num"><?= pct($m['cohesion'], 1) ?></td>
            <td class="num"><?= $neg ? $m['mutual_pairs'] : $m['no_received'] ?></td><td class="num"><?= $m['no_mutual'] ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endforeach; ?>
    <div class="card">
      <h2>Intervenções registradas</h2>
      <table><thead><tr><th>Turma</th><th>Ação</th><th>Categoria</th><th>Responsável</th><th>Situação</th><th>Prazo</th></tr></thead><tbody>
      <?php foreach (Database::all('SELECT i.*, c.name AS class_name FROM interventions i JOIN classes c ON c.id = i.class_id WHERE i.class_id IN ('.(implode(',', $ids) ?: '0').') ORDER BY c.name, i.created_at') as $i): ?>
        <tr><td><?= e($i['class_name']) ?></td><td><?= e($i['title']) ?></td><td class="small"><?= e($i['category']) ?></td><td class="small"><?= e($i['owner_name']) ?></td>
          <td><span class="tag <?= e($i['status']) ?>"><?= e(str_replace('_', ' ', $i['status'])) ?></span></td><td class="small"><?= e(date_br($i['due_on'])) ?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
    </div>
    <?php
    echo $foot;
    page_end();

    return;
}

// ------------------------------------------------------------------ turma / atenção
$class = load_class((int) ($_GET['id'] ?? 0));
$app = Database::one("SELECT * FROM applications WHERE id = ? AND class_id = ? AND status = 'closed'", [(int) ($_GET['app'] ?? 0), $class['id']]);
if (!$app) {
    not_found();
}
$byCrit = [];
foreach ($criteria as $cid => $_) {
    $byCrit[$cid] = Sociometry::analyze((int) $app['id'], $cid);
}
$attention = Sociometry::attentionPoints($byCrit);
audit('relatorio.'.$type, $reportId.' / turma '.$class['id'].' / aplicação '.$app['id']);
$scope = $class['name'].' · '.$class['school_name'].' · '.$app['title'].' ('.date_br($app['applied_on']).')';

if ($type === 'atencao') {
    page_start('Atenção pedagógica — '.$class['name'], 'relatorios', true);
    $head('Relatório de atenção pedagógica', $scope);
    echo method_note(); ?>
    <div class="card">
      <h2><?= count($attention) ?> alunos para observação</h2>
      <p class="muted small">Ordenados pela quantidade de sinais combinados. A coluna da direita é para o registro do professor.</p>
      <table>
        <thead><tr><th style="width:24%">Aluno</th><th>Fatos registrados nesta aplicação</th><th style="width:30%">Observação do professor</th></tr></thead>
        <tbody>
        <?php foreach ($attention as $it): ?>
          <tr><td><strong><?= e($it['student']['name']) ?></strong><br><span class="small muted">nº <?= $it['student']['call_number'] ?></span></td>
            <td><ul class="reasons"><?php foreach ($it['reasons'] as $r): ?><li><?= e($r) ?></li><?php endforeach; ?></ul></td>
            <td style="border-left:1px solid var(--line)">&nbsp;</td></tr>
        <?php endforeach; ?>
        <?php if (!$attention): ?><tr><td colspan="3" class="muted">Nenhum aluno reuniu sinais suficientes nesta aplicação.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="card">
      <h2>Sugestões de encaminhamento</h2>
      <ul class="reasons">
        <li>Conferir se o padrão se repete nas três perguntas e na aplicação anterior (use o comparativo).</li>
        <li>Usar a sugestão de grupos para aproximar o aluno de um colega que ele escolheu.</li>
        <li>Registrar a ação combinada em Intervenções e reavaliar na próxima aplicação.</li>
      </ul>
    </div>
    <?php
    echo $foot;
    page_end();

    return;
}

page_start('Relatório da turma — '.$class['name'], 'relatorios', true);
$head('Relatório sociométrico da turma', $scope);
$m1 = $byCrit[1]['metrics'];
?>
<div class="stats">
  <?= kpi('Alunos elegíveis', (string) $m1['eligible']) ?>
  <?= kpi('Participantes', (string) $m1['participants'], pct($m1['participation'])) ?>
  <?= kpi('Não participantes', (string) $m1['absent']) ?>
  <?= kpi('Pontos de atenção', (string) count($attention), 'alunos para observar') ?>
</div>
<div class="card">
  <h2>Síntese por pergunta</h2>
  <table>
    <thead><tr><th>Pergunta</th><th class="num">Escolhas</th><th class="num">Pares recíprocos</th><th class="num">Reciprocidade</th><th class="num">Densidade</th><th class="num">Coesão</th><th class="num">Sem indicações</th><th class="num">Sem recíproca</th></tr></thead>
    <tbody>
    <?php foreach ($criteria as $cid => $c): $m = $byCrit[$cid]['metrics']; ?>
      <tr><td><strong><?= e($c['label']) ?></strong></td><td class="num"><?= $m['choices'] ?></td><td class="num"><?= $m['mutual_pairs'] ?></td><td class="num"><?= pct($m['reciprocity']) ?></td>
        <td class="num"><?= pct($m['density'], 1) ?></td><td class="num"><?= pct($m['cohesion'], 1) ?></td><td class="num"><?= $m['no_received'] ?></td><td class="num"><?= $m['no_mutual'] ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php foreach ($criteria as $cid => $c): $an = $byCrit[$cid]; ?>
<div class="card<?= $cid > 1 ? ' page-break' : '' ?>">
  <h2>Sociograma — <?= e($c['label']) ?></h2>
  <p class="muted small">“<?= e($c['question']) ?>”</p>
  <?= sociogram($an, Sociometry::layout($an), $c, 860, 520) ?>
  <?= sociogram_legend($c) ?>
  <?php if ((int) $c['polarity'] > 0): $groups = Sociometry::subgroups($an); ?>
    <h3 style="margin-top:16px">Subgrupos por escolhas recíprocas</h3>
    <?php foreach ($groups as $i => $g): ?><p class="small" style="margin:2px 0"><strong><?= $i + 1 ?>.</strong> <?= e(implode(', ', array_map(fn ($id) => $an['students'][$id]['name'], $g))) ?></p><?php endforeach; ?>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<div class="card page-break">
  <h2>Indicadores por aluno</h2>
  <?= method_note() ?>
  <table>
    <thead><tr><th class="num">Nº</th><th>Aluno</th><?php foreach ($criteria as $c): ?><th class="num"><?= e($c['label']) ?><br>receb. / recípr.</th><?php endforeach; ?><th>Faixa (Estudo)</th><th>Respondeu</th></tr></thead>
    <tbody>
    <?php foreach ($byCrit[1]['students'] as $id => $s): ?>
      <tr><td class="num muted"><?= $s['call_number'] ?></td><td><?= e($s['name']) ?></td>
        <?php foreach ($criteria as $cid => $_): $x = $byCrit[$cid]['students'][$id]; ?><td class="num"><strong><?= $x['received'] ?></strong> / <?= $x['mutual'] ?></td><?php endforeach; ?>
        <td><?= band_badge($s['band']) ?></td><td class="small"><?= $s['participated'] ? 'Sim' : 'Não' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
echo $foot;
page_end();
