<?php
// demo/app/pages/turmas.php

declare(strict_types=1);

$ids = allowed_class_ids();
$classes = $ids ? Database::all('SELECT c.*, s.name AS school_name, s.city FROM classes c JOIN schools s ON s.id = c.school_id WHERE c.id IN ('.implode(',', $ids).') ORDER BY s.name, c.name') : [];
$bySchool = [];
foreach ($classes as $c) {
    $bySchool[$c['school_name']][] = $c;
}
page_start('Turmas', 'turmas');
page_head('Turmas', 'Escolas e turmas que você pode acessar.');
foreach ($bySchool as $school => $list): ?>
<div class="card">
  <h2><?= e($school) ?></h2>
  <p class="muted small"><?= e($list[0]['city']) ?> · ano letivo <?= e($list[0]['school_year']) ?></p>
  <table>
    <thead><tr><th>Turma</th><th>Docente</th><th class="num">Alunos</th><th class="num">Aplicações</th><th>Situação</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($list as $c):
        $n = (int) Database::value('SELECT COUNT(*) FROM students WHERE class_id = ?', [$c['id']]);
        $apps = Sociometry::applications((int) $c['id']);
        $open = array_values(array_filter($apps, fn ($a) => $a['status'] === 'open')); ?>
      <tr>
        <td><a href="<?= e(url('turma', ['id' => $c['id']])) ?>"><strong><?= e($c['name']) ?></strong></a></td>
        <td><?= e($c['teacher_name']) ?></td>
        <td class="num"><?= $n ?></td>
        <td class="num"><?= count($apps) ?></td>
        <td><?= $open ? '<a class="tag open" href="'.e(url('aplicacao', ['id' => $open[0]['id']])).'">Aplicação em andamento</a>' : '<span class="tag">Sem aplicação aberta</span>' ?></td>
        <td style="white-space:nowrap">
          <a class="btn sm" href="<?= e(url('turma', ['id' => $c['id']])) ?>">Sociograma</a>
          <a class="btn sm" href="<?= e(url('comparar', ['id' => $c['id']])) ?>">Comparar</a>
          <a class="btn sm" href="<?= e(url('grupos', ['id' => $c['id']])) ?>">Grupos</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endforeach;
page_end();
