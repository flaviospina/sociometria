<?php
// demo/app/pages/exportar.php — exportações em CSV (abre no Excel/Planilhas).

declare(strict_types=1);

$class = load_class((int) ($_GET['id'] ?? 0));
$app = Database::one("SELECT * FROM applications WHERE id = ? AND class_id = ? AND status = 'closed'", [(int) ($_GET['app'] ?? 0), $class['id']]);
$crit = Sociometry::criteria()[(int) ($_GET['crit'] ?? 0)] ?? null;
$type = $_GET['tipo'] ?? '';
if (!$app || !$crit || !in_array($type, ['matriz', 'indicadores'], true)) {
    not_found();
}
$an = Sociometry::analyze((int) $app['id'], (int) $crit['id']);
audit('exportacao.'.$type, 'turma '.$class['id'].' / aplicação '.$app['id'].' / critério '.$crit['code']);

// Evita que uma célula seja interpretada como fórmula pela planilha.
$safe = fn (string $v) => preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v;

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$type.'_'.preg_replace('/[^a-z0-9]+/i', '-', $class['name']).'_'.$app['applied_on'].'_'.$crit['code'].'.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");   // BOM: acentos corretos no Excel
if ($type === 'indicadores') {
    fputcsv($out, ['Nº', 'Aluno', 'Respondeu', 'Indicações recebidas', 'Escolhas feitas', 'Recíprocas', 'Faixa'], ';');
    foreach ($an['students'] as $s) {
        fputcsv($out, [$s['call_number'], $safe($s['name']), $s['participated'] ? 'Sim' : 'Não', $s['received'], $s['given'], $s['mutual'], Sociometry::BANDS[$s['band']]], ';');
    }
} else {
    fputcsv($out, array_merge(['Quem escolheu \\ Quem foi escolhido'], array_map(fn ($s) => $safe($s['name']), $an['students'])), ';');
    foreach ($an['students'] as $a => $sa) {
        $row = [$safe($sa['name'])];
        foreach ($an['students'] as $b => $_) {
            $row[] = $a === $b ? '' : (isset($an['matrix'][$a][$b]) ? 1 : 0);
        }
        fputcsv($out, $row, ';');
    }
}
fclose($out);
