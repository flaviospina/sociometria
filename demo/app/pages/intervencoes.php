<?php
// demo/app/pages/intervencoes.php — plano de intervenção e acompanhamento.

declare(strict_types=1);

$ids = allowed_class_ids();
$statuses = ['planejada' => 'Planejada', 'em_andamento' => 'Em andamento', 'concluida' => 'Concluída'];
$categories = ['Agrupamento', 'Convivência', 'Mediação', 'Aprendizagem', 'Família', 'Outra'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['acao'] ?? '') === 'status') {
        $row = Database::one('SELECT * FROM interventions WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
        if ($row && in_array((int) $row['class_id'], $ids, true) && isset($statuses[$_POST['status'] ?? ''])) {
            Database::run('UPDATE interventions SET status = ? WHERE id = ?', [$_POST['status'], $row['id']]);
            audit('intervencao.status', 'intervenção '.$row['id'].' => '.$_POST['status']);
            flash('Situação atualizada.');
        }
    } else {
        $classId = (int) ($_POST['class_id'] ?? 0);
        $class = load_class($classId);
        $studentId = (int) ($_POST['student_id'] ?? 0) ?: null;
        if ($studentId && !Database::one('SELECT id FROM students WHERE id = ? AND class_id = ?', [$studentId, $classId])) {
            $studentId = null;
        }
        $title = trim((string) ($_POST['title'] ?? ''));
        $desc = trim((string) ($_POST['description'] ?? ''));
        $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['due_on'] ?? '')) ? $_POST['due_on'] : null;
        $cat = in_array($_POST['category'] ?? '', $categories, true) ? $_POST['category'] : 'Outra';
        if ($title === '' || $desc === '' || mb_strlen($title) > 160 || mb_strlen($desc) > 2000) {
            flash('Preencha o título (até 160 caracteres) e a descrição.');
        } else {
            Database::run(
                'INSERT INTO interventions (class_id, student_id, title, description, category, status, owner_name, due_on, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [$classId, $studentId, $title, $desc, $cat, 'planejada', current_user()['name'], $due],
            );
            audit('intervencao.criada', 'turma '.$classId);
            flash('Intervenção registrada.');
        }
    }
    redirect('intervencoes');
}

$list = $ids ? Database::all('SELECT i.*, c.name AS class_name, s.name AS student_name FROM interventions i JOIN classes c ON c.id = i.class_id LEFT JOIN students s ON s.id = i.student_id WHERE i.class_id IN ('.implode(',', $ids).") ORDER BY FIELD(i.status, 'em_andamento', 'planejada', 'concluida'), i.due_on") : [];
$classes = $ids ? Database::all('SELECT id, name FROM classes WHERE id IN ('.implode(',', $ids).') ORDER BY name') : [];
$preClass = (int) ($_GET['turma'] ?? ($classes[0]['id'] ?? 0));
$preStudent = (int) ($_GET['aluno'] ?? 0);
$students = $preClass && in_array($preClass, $ids, true) ? Database::all('SELECT id, name FROM students WHERE class_id = ? ORDER BY name', [$preClass]) : [];

page_start('Intervenções', 'intervencoes');
page_head('Intervenções pedagógicas', 'Registre o que foi combinado e acompanhe o efeito na aplicação seguinte.');
$count = array_count_values(array_column($list, 'status'));
?>
<div class="stats">
  <?php foreach ($statuses as $k => $label): ?><?= kpi($label, (string) ($count[$k] ?? 0)) ?><?php endforeach; ?>
</div>
<div class="card">
  <h2>Plano de intervenção</h2>
  <div class="table-wrap"><table>
    <thead><tr><th>Ação</th><th>Turma / aluno</th><th>Categoria</th><th>Responsável</th><th>Prazo</th><th>Situação</th><th>Resultado observado</th></tr></thead>
    <tbody>
    <?php foreach ($list as $i): $ov = Sociometry::overview((int) $i['class_id']); ?>
      <tr>
        <td style="max-width:340px"><strong><?= e($i['title']) ?></strong><br><span class="small muted"><?= e($i['description']) ?></span></td>
        <td class="small"><a href="<?= e(url('turma', ['id' => $i['class_id']])) ?>"><?= e($i['class_name']) ?></a><?= $i['student_name'] ? '<br>'.e($i['student_name']) : '' ?></td>
        <td class="small"><?= e($i['category']) ?></td><td class="small"><?= e($i['owner_name']) ?></td><td class="small"><?= e(date_br($i['due_on'])) ?></td>
        <td>
          <form method="post" action="<?= e(url('intervencoes')) ?>" style="display:flex;gap:6px;align-items:center">
            <?= csrf_field() ?><input type="hidden" name="acao" value="status"><input type="hidden" name="id" value="<?= (int) $i['id'] ?>">
            <select name="status" style="padding:5px 8px;width:auto" aria-label="Situação">
              <?php foreach ($statuses as $k => $label): ?><option value="<?= $k ?>"<?= $k === $i['status'] ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select><button class="btn sm">Salvar</button>
          </form>
        </td>
        <td class="small"><?php if ($ov['previous']): $n = $ov['now'][1]['metrics']; $b = $ov['before'][1]['metrics']; ?>
          Sem indicações (Estudo): <?= $b['no_received'] ?> → <strong><?= $n['no_received'] ?></strong> <?= delta_badge($n['no_received'], $b['no_received'], false, false) ?><br>
          <a href="<?= e(url('comparar', ['id' => $i['class_id']])) ?>">ver comparativo</a>
        <?php else: ?><span class="muted">aguardando reaplicação</span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<div class="card" id="nova">
  <h2>Nova intervenção</h2>
  <form method="post" action="<?= e(url('intervencoes')) ?>">
    <?= csrf_field() ?>
    <div class="form-row">
      <div><label for="class_id">Turma</label>
        <select id="class_id" name="class_id" onchange="location='<?= e(url('intervencoes')) ?>&turma='+this.value+'#nova'">
          <?php foreach ($classes as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (int) $c['id'] === $preClass ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div><label for="student_id">Alcance</label>
        <select id="student_id" name="student_id"><option value="">Turma inteira</option>
          <?php foreach ($students as $s): ?><option value="<?= (int) $s['id'] ?>"<?= (int) $s['id'] === $preStudent ? ' selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div><label for="category">Categoria</label>
        <select id="category" name="category"><?php foreach ($categories as $c): ?><option><?= e($c) ?></option><?php endforeach; ?></select></div>
      <div><label for="due_on">Prazo</label><input type="date" id="due_on" name="due_on"></div>
    </div>
    <label for="title">Título</label><input type="text" id="title" name="title" maxlength="160" required>
    <label for="description">O que será feito</label><textarea id="description" name="description" rows="3" maxlength="2000" required></textarea>
    <p style="margin-top:14px"><button class="btn primary">Registrar intervenção</button></p>
  </form>
</div>
<?php page_end();
