<?php
// demo/app/view.php — moldura das páginas e componentes visuais reutilizados.

declare(strict_types=1);

/** Abre a página (cabeçalho, menu lateral). Feche com page_end(). */
function page_start(string $title, string $active = '', bool $print = false): void
{
    $u = current_user();
    $menu = [
        'painel' => ['Painel', 'M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z'],
        'turmas' => ['Turmas', 'M16 11a3 3 0 100-6 3 3 0 000 6zm-8 0a3 3 0 100-6 3 3 0 000 6zm0 2c-2.3 0-7 1.2-7 3.5V19h14v-2.5C15 14.2 10.3 13 8 13zm8 0c-.3 0-.6 0-1 .1 1.2.800 2 2 2 3.400V19h6v-2.5c0-2.3-4.7-3.5-7-3.500z'],
        'relatorios' => ['Relatórios', 'M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.500L18.5 9H13z'],
        'intervencoes' => ['Intervenções', 'M9 16.200L4.8 12l-1.4 1.400L9 19 21 7l-1.4-1.400L9 16.200z'],
        'metodologia' => ['Metodologia', 'M11 17h2v-6h-2v6zm1-15a10 10 0 100 20 10 10 0 000-20zm0 18a8 8 0 110-16 8 8 0 010 16zM11 9h2V7h-2v2z'],
    ];
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> — <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/app.css?v=3">
</head>
<body class="<?= $print ? 'report-mode' : '' ?>">
<?php if ($u): ?>
<aside class="sidebar no-print">
  <a class="brand" href="<?= e(url('painel')) ?>">
    <svg viewBox="0 0 40 40" width="34" height="34" aria-hidden="true"><circle cx="20" cy="20" r="19" fill="#2a78d6"/><g stroke="#fff" stroke-width="1.6"><line x1="12" y1="14" x2="27" y2="12"/><line x1="12" y1="14" x2="20" y2="27"/><line x1="27" y1="12" x2="20" y2="27"/><line x1="27" y1="12" x2="30" y2="24"/></g><g fill="#fff"><circle cx="12" cy="14" r="3.4"/><circle cx="27" cy="12" r="3.4"/><circle cx="20" cy="27" r="3.4"/><circle cx="30" cy="24" r="2.6"/></g></svg>
    <span>Sociometria<small>Escolar</small></span>
  </a>
  <nav>
    <?php foreach ($menu as $key => [$label, $icon]): ?>
      <a href="<?= e(url($key)) ?>" class="<?= $active === $key ? 'on' : '' ?>">
        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="<?= $icon ?>"/></svg><?= e($label) ?>
      </a>
    <?php endforeach; ?>
  </nav>
  <div class="who">
    <strong><?= e($u['name']) ?></strong>
    <span><?= $u['role'] === 'admin' ? 'Gestão — todas as turmas' : 'Docente — turmas atribuídas' ?></span>
    <a href="<?= e(url('sair')) ?>">Sair</a>
  </div>
</aside>
<?php endif; ?>
<main class="<?= $u ? 'with-sidebar' : '' ?>">
  <div class="demo-banner">Ambiente de demonstração — todos os nomes e respostas são fictícios.</div>
  <?php if ($m = flash()): ?><div class="flash"><?= e($m) ?></div><?php endif; ?>
<?php
}

function page_end(): void
{
    ?>
</main>
<div id="tip" role="tooltip" hidden></div>
<script src="assets/app.js?v=3"></script>
</body>
</html>
<?php
}

/** Cabeçalho de página: título, subtítulo e ações à direita. */
function page_head(string $title, string $subtitle = '', string $actionsHtml = '', array $crumbs = []): void
{
    echo '<header class="page-head"><div>';
    if ($crumbs) {
        echo '<nav class="crumbs no-print">';
        foreach ($crumbs as $label => $href) {
            echo '<a href="'.e($href).'">'.e($label).'</a><span>/</span>';
        }
        echo '</nav>';
    }
    echo '<h1>'.e($title).'</h1>';
    if ($subtitle !== '') {
        echo '<p>'.e($subtitle).'</p>';
    }
    echo '</div><div class="actions no-print">'.$actionsHtml.'</div></header>';
}

/** Cartão de indicador (número em destaque). */
function kpi(string $label, string $value, string $hint = '', string $extraHtml = ''): string
{
    return '<div class="stat"><span class="stat-label">'.e($label).'</span><strong>'.e($value).'</strong>'
        .($extraHtml !== '' ? $extraHtml : '')
        .($hint !== '' ? '<span class="stat-hint">'.e($hint).'</span>' : '').'</div>';
}

/** Barra horizontal fina (uma medida, uma cor); o valor vai ao lado, em texto. */
function bar(float $ratio, string $class = 's1', string $tip = ''): string
{
    $w = max(0, min(100, $ratio * 100));

    return '<span class="bar"'.($tip !== '' ? ' data-tip="'.e($tip).'"' : '').'><i class="'.$class.'" style="width:'.number_format($w, 1, '.', '').'%"></i></span>';
}

/** Selo da faixa de indicações: ícone + texto (nunca só cor). */
function band_badge(string $band): string
{
    $icon = ['muitas' => '▲', 'media' => '●', 'poucas' => '▽', 'nenhuma' => '○'][$band];

    return '<span class="band band-'.$band.'">'.$icon.' '.e(Sociometry::BANDS[$band]).'</span>';
}

/** Abas/filtros como links (funcionam sem JavaScript). */
function pills(array $options, string|int $current, callable $href): string
{
    $h = '<div class="pills no-print">';
    foreach ($options as $value => $label) {
        $h .= '<a class="'.((string) $value === (string) $current ? 'on' : '').'" href="'.e($href($value)).'">'.e($label).'</a>';
    }

    return $h.'</div>';
}

/** Aviso metodológico padrão exibido junto de dados individuais. */
function method_note(): string
{
    return '<p class="method-note"><strong>Leitura responsável.</strong> Os números descrevem as escolhas registradas '
        .'neste questionário, nesta data. Não receber indicações não significa rejeição nem indica qualquer condição do aluno: '
        .'é um convite à observação pelo professor, que conhece o contexto da turma.</p>';
}

/**
 * Sociograma em SVG. Tamanho e tom do nó = indicações recebidas;
 * linha cheia = escolha recíproca; linha fina com seta = escolha em um sentido.
 */
function sociogram(array $analysis, array $layout, array $criterion, int $w = 860, int $h = 560, bool $labels = true): string
{
    $pad = 46;
    $neg = (int) $criterion['polarity'] < 0;
    $max = max(1, max(array_column($analysis['students'], 'received')));
    $xy = [];
    foreach ($layout as $id => [$x, $y]) {
        $xy[$id] = [$pad + $x * ($w - 2 * $pad), $pad + $y * ($h - 2 * $pad)];
    }
    $ramp = $neg
        ? ['#f6ddd0', '#f3b89d', '#ef8e63', '#eb6834', '#b84a1e']
        : ['#cde2fb', '#9ec5f4', '#5598e7', '#2a78d6', '#184f95'];
    $s = '<svg class="sociogram'.($neg ? ' neg' : '').'" viewBox="0 0 '.$w.' '.$h.'" role="img" aria-label="Sociograma do critério '.e($criterion['label']).'">';
    $s .= '<defs><marker id="arr" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z" class="arrow-head"/></marker></defs>';

    $radius = fn (int $rec) => 11 + 11 * sqrt($rec / $max);
    $s .= '<g class="edges">';
    foreach ($analysis['edges'] as [$a, $b, $mutual]) {
        if ($mutual && $a > $b) {
            continue;   // par recíproco desenhado uma única vez
        }
        [$x1, $y1] = $xy[$a];
        [$x2, $y2] = $xy[$b];
        $d = max(1, hypot($x2 - $x1, $y2 - $y1));
        $ra = $radius($analysis['students'][$a]['received']) + 2;
        $rb = $radius($analysis['students'][$b]['received']) + ($mutual ? 2 : 5);
        $s .= sprintf(
            '<line class="%s" data-a="%d" data-b="%d" x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f"%s/>',
            $mutual ? 'mutual' : 'oneway', $a, $b,
            $x1 + ($x2 - $x1) * $ra / $d, $y1 + ($y2 - $y1) * $ra / $d,
            $x2 - ($x2 - $x1) * $rb / $d, $y2 - ($y2 - $y1) * $rb / $d,
            $mutual ? '' : ' marker-end="url(#arr)"',
        );
    }
    $s .= '</g><g class="nodes">';
    foreach ($analysis['students'] as $id => $st) {
        [$x, $y] = $xy[$id];
        $r = $radius($st['received']);
        $step = $st['received'] === 0 ? -1 : (int) min(4, floor(($st['received'] / $max) * 4.999));
        $fill = $step < 0 ? '#ffffff' : $ramp[$step];
        $dark = $step >= 2;
        $tip = $st['name'].' — '.$st['received'].' indicação(ões) recebida(s), '.$st['given'].' feita(s), '.$st['mutual'].' recíproca(s)'
            .($st['participated'] ? '' : ' · não respondeu');
        $s .= sprintf(
            '<a href="%s" class="node%s%s" data-id="%d" data-tip="%s"><circle cx="%.1f" cy="%.1f" r="%.1f" fill="%s"/><text x="%.1f" y="%.1f" class="%s">%s</text>%s</a>',
            e(url('aluno', ['id' => $id])), $st['received'] === 0 && !$neg ? ' zero' : '', $st['participated'] ? '' : ' absent',
            $id, e($tip), $x, $y, $r, $fill, $x, $y + 3.6, $dark ? 'on-dark' : 'on-light', e(initials($st['name'])),
            $labels ? sprintf('<text x="%.1f" y="%.1f" class="name">%s</text>', $x, $y + $r + 12, e(explode(' ', $st['name'])[0])) : '',
        );
    }

    return $s.'</g></svg>';
}

/** Legenda do sociograma. */
function sociogram_legend(array $criterion): string
{
    $neg = (int) $criterion['polarity'] < 0;

    return '<div class="legend"><span><i class="lg-node big'.($neg ? ' neg' : '').'"></i> mais indicações recebidas</span>'
        .'<span><i class="lg-node small'.($neg ? ' neg' : '').'"></i> menos indicações</span>'
        .($neg ? '<span><i class="lg-node small" style="background:#fff"></i> não citado</span>' : '<span><i class="lg-node zero"></i> nenhuma indicação recebida</span>')
        .'<span><i class="lg-line mutual'.($neg ? ' neg' : '').'"></i> escolha recíproca</span>'
        .'<span><i class="lg-line oneway"></i> escolha em um sentido (→)</span>'
        .'<span><i class="lg-node absent"></i> não respondeu</span></div>';
}
