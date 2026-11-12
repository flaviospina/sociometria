<?php
// demo/app/pages/turma.php — sociograma, matriz, indicadores e subgrupos de uma aplicação.

declare(strict_types=1);

$class = load_class((int) ($_GET['id'] ?? 0));
$apps = Sociometry::applications((int) $class['id'], true);
if (!$apps) {
    page_start($class['name'], 'turmas');
    page_head($class['name'], 'Esta turma ainda não tem aplicação encerrada.');
    page_end();

    return;
}
$appIds = array_column($apps, null, 'id');
$app = $appIds[(int) ($_GET['app'] ?? 0)] ?? end($apps);
$criteria = Sociometry::criteria();
$crit = $criteria[(int) ($_GET['crit'] ?? 0)] ?? reset($criteria);
$view = in_array($_GET['v'] ?? '', ['sociograma', 'matriz', 'indicadores', 'subgrupos'], true) ? $_GET['v'] : 'sociograma';
$link = fn (array $over) => url('turma', $over + ['id' => $class['id'], 'app' => $app['id'], 'crit' => $crit['id'], 'v' => $view]);

$an = Sociometry::analyze((int) $app['id'], (int) $crit['id']);
$m = $an['metrics'];
$neg = (int) $crit['polarity'] < 0;
audit('turma.visualizada', 'turma '.$class['id'].' / aplicação '.$app['id']);

page_start($class['name'], 'turmas');
page_head($class['name'], $class['school_name'].' · '.$class['teacher_name'],
    '<a class="btn" href="'.e(url('grupos', ['id' => $class['id'], 'app' => $app['id']])).'">Sugerir grupos</a>'
    .'<a class="btn" href="'.e(url('comparar', ['id' => $class['id']])).'">Comparar aplicações</a>'
    .'<a class="btn primary" href="'.e(url('relatorio', ['tipo' => 'turma', 'id' => $class['id'], 'app' => $app['id']])).'">Relatório da turma</a>',
    ['Turmas' => url('turmas')]);
?>
<div class="toolbar">
  <span class="lbl">Aplicação</span>
  <?= pills(array_map(fn ($a) => date_br($a['applied_on']), $appIds), $app['id'], fn ($v) => $link(['app' => $v])) ?>
  <span class="lbl">Pergunta</span>
  <?= pills(array_map(fn ($c) => $c['label'], $criteria), $crit['id'], fn ($v) => $link(['crit' => $v])) ?>
</div>
<p class="muted" style="margin-top:-6px">“<?= e($crit['question']) ?>” — até <?= (int) $crit['max_choices'] ?> escolhas por aluno.</p>

<div class="stats">
  <?= kpi('Participação', pct($m['participation']), $m['participants'].' de '.$m['eligible'].' alunos') ?>
  <?= kpi('Escolhas registradas', (string) $m['choices'], 'média de '.num($m['mean_received']).' por aluno') ?>
  <?= kpi($neg ? 'Dificuldade recíproca' : 'Pares recíprocos', (string) $m['mutual_pairs'], $neg ? 'pares que se citaram' : 'escolhas correspondidas') ?>
  <?= kpi('Reciprocidade', pct($m['reciprocity']), 'das escolhas feitas') ?>
  <?= kpi('Densidade', pct($m['density'], 1), 'das ligações possíveis') ?>
  <?= kpi($neg ? 'Não citados' : 'Sem indicações', (string) $m['no_received'], $neg ? 'alunos' : 'recebidas neste critério') ?>
</div>

<?= pills(['sociograma' => 'Sociograma', 'matriz' => 'Matriz', 'indicadores' => 'Indicadores por aluno', 'subgrupos' => 'Subgrupos'], $view, fn ($v) => $link(['v' => $v])) ?>
<div style="height:14px"></div>

<?php if ($view === 'sociograma'): ?>
<div class="grid g-main">
  <div class="card">
    <h2>Sociograma — <?= e($crit['label']) ?></h2>
    <p class="muted small">Passe o mouse sobre um aluno para destacar seus vínculos; clique para abrir a ficha individual.</p>
    <?= sociogram($an, Sociometry::layout($an), $crit) ?>
    <?= sociogram_legend($crit) ?>
  </div>
  <div>
    <div class="card">
      <h2><?= $neg ? 'Mais citados' : 'Mais indicados' ?></h2>
      <table><tbody>
      <?php $rank = $an['students'];
      usort($rank, fn ($a, $b) => $b['received'] <=> $a['received'] ?: strcmp($a['name'], $b['name']));
      $maxRec = max(1, $rank[0]['received']);
      foreach (array_slice($rank, 0, 6) as $s): ?>
        <tr><td><a href="<?= e(url('aluno', ['id' => $s['id']])) ?>"><?= e($s['name']) ?></a>
          <div class="barcell"><?= bar($s['received'] / $maxRec, $neg ? 's2' : 's1', $s['received'].' indicações') ?><b><?= $s['received'] ?></b></div></td></tr>
      <?php endforeach; ?>
      </tbody></table>
    </div>
    <?php if (!$neg): ?>
    <div class="card">
      <h2>Sem indicações recebidas</h2>
      <?php $zero = array_filter($an['students'], fn ($s) => $s['received'] === 0); ?>
      <?php if ($zero): ?>
        <div class="chips"><?php foreach ($zero as $s): ?><a class="chip warn" href="<?= e(url('aluno', ['id' => $s['id']])) ?>"><?= e($s['name']) ?></a><?php endforeach; ?></div>
        <p class="small muted" style="margin-top:10px">Observe se o padrão se repete nas outras perguntas e na próxima aplicação antes de qualquer conclusão.</p>
      <?php else: ?>
        <p class="muted">Todos os alunos receberam ao menos uma indicação neste critério.</p>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php elseif ($view === 'matriz'): ?>
<div class="card">
  <h2>Matriz sociométrica — <?= e($crit['label']) ?></h2>
  <p class="muted small">Linha = quem escolheu; coluna = quem foi escolhido. Tom escuro = escolha recíproca. Números = número de chamada.</p>
  <div class="table-wrap"><table class="matrix<?= $neg ? ' neg' : '' ?>">
    <thead><tr><th></th><?php foreach ($an['students'] as $s): ?><th data-tip="<?= e($s['name']) ?>"><?= $s['call_number'] ?></th><?php endforeach; ?><th>Feitas</th></tr></thead>
    <tbody>
    <?php foreach ($an['students'] as $a => $sa): ?>
      <tr><th class="rowh"><?= e($sa['name']) ?> · <?= $sa['call_number'] ?></th>
      <?php foreach ($an['students'] as $b => $sb):
          $cls = $a === $b ? 'self' : (isset($an['matrix'][$a][$b]) ? (isset($an['matrix'][$b][$a]) ? 'c2' : 'c1') : '');
          $tipTxt = $cls === 'c2' ? $sa['name'].' ⇄ '.$sb['name'].' (recíproca)' : ($cls === 'c1' ? $sa['name'].' → '.$sb['name'] : ''); ?>
        <td class="<?= $cls ?>"<?= $tipTxt ? ' data-tip="'.e($tipTxt).'"' : '' ?>></td>
      <?php endforeach; ?>
        <td class="tot"><?= $sa['participated'] ? $sa['given'] : '—' ?></td></tr>
    <?php endforeach; ?>
      <tr><th class="rowh">Recebidas</th><?php foreach ($an['students'] as $s): ?><td class="tot" style="padding:4px 0 0"><?= $s['received'] ?></td><?php endforeach; ?><td></td></tr>
    </tbody>
  </table></div>
  <div class="legend"><span><i class="lg-node small<?= $neg ? ' neg' : '' ?>" style="border-radius:3px;background:<?= $neg ? '#f3b89d' : '#86b6ef' ?>"></i> escolha em um sentido</span><span><i class="lg-node big<?= $neg ? ' neg' : '' ?>" style="border-radius:3px;width:13px;height:13px"></i> escolha recíproca</span><span>— na coluna “Feitas”: aluno não respondeu</span></div>
  <p style="margin-top:14px"><a class="btn sm" href="<?= e(url('exportar', ['tipo' => 'matriz', 'id' => $class['id'], 'app' => $app['id'], 'crit' => $crit['id']])) ?>">Exportar matriz (CSV)</a></p>
</div>

<?php elseif ($view === 'indicadores'): ?>
<div class="card">
  <h2>Indicadores por aluno — <?= e($crit['label']) ?></h2>
  <?= method_note() ?>
  <div class="table-wrap"><table>
    <thead><tr><th class="num">Nº</th><th>Aluno</th><th style="width:22%">Indicações recebidas</th><th class="num">Feitas</th><th class="num">Recíprocas</th><?php if (!$neg): ?><th>Faixa na turma</th><?php endif; ?><th>Participação</th></tr></thead>
    <tbody>
    <?php $maxRec = max(1, max(array_column($an['students'], 'received')));
    foreach ($an['students'] as $s): ?>
      <tr>
        <td class="num muted"><?= $s['call_number'] ?></td>
        <td><a href="<?= e(url('aluno', ['id' => $s['id']])) ?>"><?= e($s['name']) ?></a></td>
        <td><div class="barcell"><?= bar($s['received'] / $maxRec, $neg ? 's2' : 's1') ?><b><?= $s['received'] ?></b></div></td>
        <td class="num"><?= $s['participated'] ? $s['given'] : '—' ?></td>
        <td class="num"><?= $s['mutual'] ?></td>
        <?php if (!$neg): ?><td><?= band_badge($s['band']) ?></td><?php endif; ?>
        <td class="small"><?= $s['participated'] ? 'Respondeu' : '<span class="muted">Não respondeu</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p style="margin-top:14px"><a class="btn sm" href="<?= e(url('exportar', ['tipo' => 'indicadores', 'id' => $class['id'], 'app' => $app['id'], 'crit' => $crit['id']])) ?>">Exportar indicadores (CSV)</a></p>
</div>

<?php else: $groups = Sociometry::subgroups($an); ?>
<div class="card">
  <h2>Subgrupos por escolhas recíprocas — <?= e($crit['label']) ?></h2>
  <p class="muted small">Cada subgrupo reúne alunos ligados entre si por escolhas correspondidas. Subgrupos muito fechados ou alunos fora de qualquer subgrupo ajudam a planejar agrupamentos.</p>
  <?php foreach ($groups as $i => $g): ?>
    <h3 style="margin-top:14px">Subgrupo <?= $i + 1 ?> <span class="muted">· <?= count($g) ?> alunos</span></h3>
    <div class="chips"><?php foreach ($g as $id): ?><a class="chip mutual" href="<?= e(url('aluno', ['id' => $id])) ?>"><?= e($an['students'][$id]['name']) ?></a><?php endforeach; ?></div>
  <?php endforeach; ?>
  <?php $out = array_filter($an['students'], fn ($s) => !$s['mutual_with']); ?>
  <h3 style="margin-top:18px">Sem escolha recíproca neste critério <span class="muted">· <?= count($out) ?> alunos</span></h3>
  <div class="chips"><?php foreach ($out as $s): ?><a class="chip<?= $s['participated'] ? ' warn' : '' ?>" href="<?= e(url('aluno', ['id' => $s['id']])) ?>"><?= e($s['name']) ?><?= $s['participated'] ? '' : ' (não respondeu)' ?></a><?php endforeach; ?></div>
</div>
<?php endif;
page_end();
