<?php
// demo/app/pages/relatorios.php — central de relatórios.

declare(strict_types=1);

$ids = allowed_class_ids();
$classes = $ids ? Database::all('SELECT c.*, s.name AS school_name FROM classes c JOIN schools s ON s.id = c.school_id WHERE c.id IN ('.implode(',', $ids).') ORDER BY s.name, c.name') : [];
page_start('Relatórios', 'relatorios');
page_head('Relatórios', 'Documentos prontos para reunião pedagógica, conselho de classe e acompanhamento. Todos abrem em versão para impressão ou PDF.');
?>
<div class="grid g3">
  <div class="card"><h2>Relatório institucional</h2><p class="muted small">Todas as turmas lado a lado: participação, reciprocidade, coesão, evolução e intervenções. Para a direção e a coordenação.</p>
    <a class="btn primary" href="<?= e(url('relatorio', ['tipo' => 'institucional'])) ?>">Abrir</a></div>
  <div class="card"><h2>Relatório da turma</h2><p class="muted small">Sociogramas das três perguntas, indicadores, subgrupos e pontos de atenção de uma aplicação. Para o conselho de classe.</p>
    <span class="muted small">Escolha a turma abaixo</span></div>
  <div class="card"><h2>Atenção pedagógica</h2><p class="muted small">Lista de alunos para observação, com os fatos que motivaram cada indicação e espaço para o registro do professor.</p>
    <span class="muted small">Escolha a turma abaixo</span></div>
</div>
<div class="card">
  <h2>Por turma</h2>
  <table>
    <thead><tr><th>Turma</th><th>Aplicação mais recente</th><th>Relatórios</th><th>Planilhas (critério Estudo)</th></tr></thead>
    <tbody>
    <?php foreach ($classes as $c): $ov = Sociometry::overview((int) $c['id']);
        if (!$ov['latest']) { continue; } $app = $ov['latest']; ?>
      <tr>
        <td><strong><?= e($c['name']) ?></strong><br><span class="small muted"><?= e($c['school_name']) ?></span></td>
        <td class="small"><?= e($app['title']) ?><br><span class="muted"><?= e(date_br($app['applied_on'])) ?></span></td>
        <td style="white-space:nowrap">
          <a class="btn sm" href="<?= e(url('relatorio', ['tipo' => 'turma', 'id' => $c['id'], 'app' => $app['id']])) ?>">Turma</a>
          <a class="btn sm" href="<?= e(url('relatorio', ['tipo' => 'atencao', 'id' => $c['id'], 'app' => $app['id']])) ?>">Atenção pedagógica</a>
          <a class="btn sm" href="<?= e(url('comparar', ['id' => $c['id'], 'imprimir' => 1])) ?>">Comparativo</a>
          <a class="btn sm" href="<?= e(url('grupos', ['id' => $c['id'], 'app' => $app['id'], 'imprimir' => 1])) ?>">Grupos</a>
        </td>
        <td style="white-space:nowrap">
          <a class="btn sm" href="<?= e(url('exportar', ['tipo' => 'indicadores', 'id' => $c['id'], 'app' => $app['id'], 'crit' => 1])) ?>">Indicadores CSV</a>
          <a class="btn sm" href="<?= e(url('exportar', ['tipo' => 'matriz', 'id' => $c['id'], 'app' => $app['id'], 'crit' => 1])) ?>">Matriz CSV</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="small muted" style="margin-top:12px">O relatório individual é aberto a partir da ficha de cada aluno. Toda geração e exportação fica registrada.</p>
</div>
<?php page_end();
