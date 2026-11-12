<?php
// demo/app/pages/comparar.php — comparação entre duas aplicações da mesma turma.

declare(strict_types=1);

$class = load_class((int) ($_GET['id'] ?? 0));
$apps = Sociometry::applications((int) $class['id'], true);
$criteria = Sociometry::criteria();
$crit = $criteria[(int) ($_GET['crit'] ?? 0)] ?? reset($criteria);
$print = isset($_GET['imprimir']);

page_start('Comparar — '.$class['name'], 'turmas', $print);
page_head('Comparação entre aplicações', $class['name'].' · '.$class['school_name'],
    count($apps) >= 2 ? '<a class="btn primary" href="'.e(url('comparar', ['id' => $class['id'], 'crit' => $crit['id'], 'imprimir' => 1])).'">Versão para impressão</a>' : '',
    ['Turmas' => url('turmas'), $class['name'] => url('turma', ['id' => $class['id']])]);
if (count($apps) < 2) {
    echo '<div class="card">São necessárias duas aplicações encerradas para comparar.</div>';
    page_end();

    return;
}
$a1 = $apps[count($apps) - 2];
$a2 = $apps[count($apps) - 1];
$x = Sociometry::analyze((int) $a1['id'], (int) $crit['id']);
$y = Sociometry::analyze((int) $a2['id'], (int) $crit['id']);
$neg = (int) $crit['polarity'] < 0;
if ($print) {
    echo '<p class="no-print"><button class="btn primary" data-print>Imprimir / salvar em PDF</button></p>';
}
echo '<div class="toolbar"><span class="lbl">Pergunta</span>'.pills(array_map(fn ($c) => $c['label'], $criteria), $crit['id'], fn ($v) => url('comparar', ['id' => $class['id'], 'crit' => $v])).'</div>';
$rows = [
    ['Participação', 'participation', true, true], ['Reciprocidade', 'reciprocity', !$neg, true], ['Coesão (pares recíprocos / possíveis)', 'cohesion', !$neg, true],
    ['Densidade', 'density', !$neg, true], ['Pares recíprocos', 'mutual_pairs', !$neg, false],
    [$neg ? 'Alunos não citados' : 'Alunos sem indicações recebidas', 'no_received', $neg, false], ['Alunos sem escolha recíproca', 'no_mutual', $neg, false],
];
?>
<div class="card">
  <h2>Indicadores da turma — <?= e($crit['label']) ?></h2>
  <table>
    <thead><tr><th>Indicador</th><th class="num"><?= e(date_br($a1['applied_on'])) ?></th><th class="num"><?= e(date_br($a2['applied_on'])) ?></th><th>Variação</th></tr></thead>
    <tbody>
    <?php foreach ($rows as [$label, $key, $higherBetter, $isPct]): ?>
      <tr><td><?= e($label) ?></td>
        <td class="num"><?= $isPct ? pct($x['metrics'][$key], 1) : $x['metrics'][$key] ?></td>
        <td class="num"><strong><?= $isPct ? pct($y['metrics'][$key], 1) : $y['metrics'][$key] ?></strong></td>
        <td><?= delta_badge((float) $y['metrics'][$key], (float) $x['metrics'][$key], $higherBetter, $isPct) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="grid g2">
  <?php foreach ([[$a1, $x], [$a2, $y]] as [$a, $an]): ?>
  <div class="card">
    <h2><?= e($a['title']) ?></h2>
    <p class="muted small"><?= e(date_br($a['applied_on'])) ?> · <?= $an['metrics']['participants'] ?> de <?= $an['metrics']['eligible'] ?> responderam</p>
    <?= sociogram($an, Sociometry::layout($an), $crit, 560, 440, false) ?>
  </div>
  <?php endforeach; ?>
</div>
<div class="card" style="padding-top:12px;padding-bottom:12px"><?= sociogram_legend($crit) ?></div>

<div class="card">
  <h2>Quem mais mudou</h2>
  <p class="muted small">Diferença de indicações recebidas entre as duas aplicações (<?= e($crit['label']) ?>).</p>
  <?php
  $changes = [];
  foreach ($y['students'] as $id => $s) {
      if (isset($x['students'][$id])) {
          $changes[] = ['s' => $s, 'before' => $x['students'][$id]['received'], 'd' => $s['received'] - $x['students'][$id]['received'], 'mb' => $x['students'][$id]['mutual']];
      }
  }
  usort($changes, fn ($p, $q) => abs($q['d']) <=> abs($p['d']) ?: strcmp($p['s']['name'], $q['s']['name']));
  ?>
  <table>
    <thead><tr><th>Aluno</th><th class="num">Antes</th><th class="num">Agora</th><th>Variação</th><th class="num">Recíprocas antes</th><th class="num">Recíprocas agora</th></tr></thead>
    <tbody>
    <?php foreach (array_slice($changes, 0, 10) as $ch): ?>
      <tr><td><a href="<?= e(url('aluno', ['id' => $ch['s']['id']])) ?>"><?= e($ch['s']['name']) ?></a></td>
        <td class="num"><?= $ch['before'] ?></td><td class="num"><strong><?= $ch['s']['received'] ?></strong></td>
        <td><?= delta_badge($ch['s']['received'], $ch['before'], !$neg, false) ?></td>
        <td class="num"><?= $ch['mb'] ?></td><td class="num"><?= $ch['s']['mutual'] ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php page_end();
