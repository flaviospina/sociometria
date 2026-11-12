<?php
// demo/app/pages/grupos.php — sugestão de grupos de trabalho a partir das escolhas.

declare(strict_types=1);

$class = load_class((int) ($_GET['id'] ?? 0));
$apps = Sociometry::applications((int) $class['id'], true);
$appIds = array_column($apps, null, 'id');
$app = $appIds[(int) ($_GET['app'] ?? 0)] ?? end($apps);
$size = max(2, min(6, (int) ($_GET['tam'] ?? 4)));
$print = isset($_GET['imprimir']);

page_start('Grupos — '.$class['name'], 'turmas', $print);
page_head('Sugestão de grupos de trabalho', $class['name'].' · com base na aplicação de '.($app ? date_br($app['applied_on']) : '—'),
    $app ? '<a class="btn primary" href="'.e(url('grupos', ['id' => $class['id'], 'app' => $app['id'], 'tam' => $size, 'imprimir' => 1])).'">Versão para impressão</a>' : '',
    ['Turmas' => url('turmas'), $class['name'] => url('turma', ['id' => $class['id']])]);
if (!$app) {
    echo '<div class="card">Sem aplicações encerradas.</div>';
    page_end();

    return;
}
$pos = Sociometry::analyze((int) $app['id'], 1);
$negA = Sociometry::analyze((int) $app['id'], 3);
$res = Sociometry::suggestGroups($pos, $negA, $size);
$served = count(array_filter($res['served']));
$n = count($res['served']);
if ($print) {
    echo '<p class="no-print"><button class="btn primary" data-print>Imprimir / salvar em PDF</button></p>';
}
echo '<div class="toolbar"><span class="lbl">Tamanho do grupo</span>'.pills([3 => '3 alunos', 4 => '4 alunos', 5 => '5 alunos'], $size, fn ($v) => url('grupos', ['id' => $class['id'], 'app' => $app['id'], 'tam' => $v])).'</div>';
?>
<div class="stats">
  <?= kpi('Grupos formados', (string) count($res['groups']), 'de até '.$size.' alunos') ?>
  <?= kpi('Com um colega escolhido', $served.' de '.$n, pct($n ? $served / $n : 0).' dos alunos') ?>
  <?= kpi('Pares com dificuldade juntos', (string) $res['conflicts'], $res['conflicts'] === 0 ? 'nenhum par indicado ficou junto' : 'revise estes grupos') ?>
</div>
<p class="method-note"><strong>Como a sugestão é feita.</strong> O sistema procura colocar cada aluno com pelo menos um colega que ele escolheu no critério Estudo, começa pelos que receberam menos indicações e evita reunir pares que se citaram no critério Dificuldade. É um ponto de partida: o professor ajusta conforme o que conhece da turma.</p>
<div class="grid g3">
<?php foreach ($res['groups'] as $i => $members): ?>
  <div class="card">
    <h2>Grupo <?= $i + 1 ?></h2>
    <table><tbody>
    <?php foreach ($members as $id): $s = $pos['students'][$id];
        $links = array_values(array_intersect($s['chose'], $members));
        $mutual = array_values(array_intersect($s['mutual_with'], $members)); ?>
      <tr><td><a href="<?= e(url('aluno', ['id' => $id])) ?>"><?= e($s['name']) ?></a>
        <div class="small muted"><?= $mutual ? '⇄ escolha recíproca no grupo' : ($links ? '→ escolheu um colega do grupo' : ($s['participated'] ? 'sem escolha atendida' : 'não respondeu')) ?></div></td></tr>
    <?php endforeach; ?>
    </tbody></table>
  </div>
<?php endforeach; ?>
</div>
<?php page_end();
