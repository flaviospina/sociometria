<?php
// demo/app/Sociometry.php — motor de cálculo sociométrico.
//
// Tudo é calculado a partir das escolhas gravadas; nada é armazenado pronto.
// Definições (N = alunos elegíveis da turma na aplicação):
//   indicações recebidas  = quantos colegas escolheram o aluno no critério
//   escolha recíproca     = A escolheu B e B escolheu A no mesmo critério
//   densidade             = escolhas feitas / (N × (N − 1))
//   reciprocidade         = escolhas que fazem parte de um par recíproco / escolhas feitas
//   coesão                = pares recíprocos / pares possíveis (N × (N − 1) / 2)
// Faixas de indicações recebidas (critérios de aproximação), em relação à
// média e ao desvio-padrão da própria turma:
//   "muitas"  > média + 1 desvio    "poucas" < média − 1 desvio (e > 0)
//   "nenhuma" = 0                   "media"  = demais casos
// São observações limitadas a este instrumento e a esta data — não são diagnóstico.

declare(strict_types=1);

final class Sociometry
{
    public const BANDS = [
        'muitas' => 'Muitas indicações',
        'media' => 'Na média da turma',
        'poucas' => 'Poucas indicações',
        'nenhuma' => 'Sem indicações recebidas',
    ];

    /** @return array<int, array<string, mixed>> critérios indexados por id */
    public static function criteria(): array
    {
        static $c = null;

        return $c ??= array_column(Database::all('SELECT * FROM criteria ORDER BY sort_order'), null, 'id');
    }

    /**
     * Análise completa de uma aplicação em um critério.
     *
     * @return array{students: array<int, array<string, mixed>>, edges: array<int, array{0:int,1:int,2:bool}>, metrics: array<string, float|int>, matrix: array<int, array<int, int>>}
     */
    public static function analyze(int $applicationId, int $criterionId): array
    {
        $students = [];
        $rows = Database::all(
            'SELECT s.id, s.name, s.call_number, p.answered_at
               FROM participations p JOIN students s ON s.id = p.student_id
              WHERE p.application_id = ? ORDER BY s.call_number',
            [$applicationId],
        );
        foreach ($rows as $r) {
            $students[(int) $r['id']] = [
                'id' => (int) $r['id'], 'name' => $r['name'], 'call_number' => (int) $r['call_number'],
                'participated' => $r['answered_at'] !== null,
                'received' => 0, 'given' => 0, 'mutual' => 0, 'band' => 'media',
                'chose' => [], 'chosen_by' => [], 'mutual_with' => [],
            ];
        }

        $matrix = [];
        $choices = Database::all(
            'SELECT chooser_id, chosen_id FROM choices WHERE application_id = ? AND criterion_id = ? ORDER BY chooser_id, choice_rank',
            [$applicationId, $criterionId],
        );
        foreach ($choices as $c) {
            $a = (int) $c['chooser_id'];
            $b = (int) $c['chosen_id'];
            if (!isset($students[$a], $students[$b])) {
                continue;
            }
            $matrix[$a][$b] = 1;
            $students[$a]['given']++;
            $students[$a]['chose'][] = $b;
            $students[$b]['received']++;
            $students[$b]['chosen_by'][] = $a;
        }

        $edges = [];
        $mutualPairs = 0;
        $mutualChoices = 0;
        foreach ($matrix as $a => $row) {
            foreach ($row as $b => $_) {
                $mutual = isset($matrix[$b][$a]);
                $edges[] = [$a, $b, $mutual];
                if ($mutual) {
                    $mutualChoices++;
                    $students[$a]['mutual']++;
                    $students[$a]['mutual_with'][] = $b;
                    if ($a < $b) {
                        $mutualPairs++;
                    }
                }
            }
        }

        $n = count($students);
        $total = count($edges);
        $received = array_column($students, 'received');
        $mean = $n ? array_sum($received) / $n : 0.0;
        $sd = $n ? sqrt(array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $received)) / $n) : 0.0;
        foreach ($students as &$s) {
            $s['band'] = match (true) {
                $s['received'] === 0 => 'nenhuma',
                $s['received'] > $mean + $sd => 'muitas',
                $s['received'] < $mean - $sd => 'poucas',
                default => 'media',
            };
        }
        unset($s);

        $participants = count(array_filter($students, fn ($s) => $s['participated']));

        return [
            'students' => $students,
            'edges' => $edges,
            'matrix' => $matrix,
            'metrics' => [
                'eligible' => $n,
                'participants' => $participants,
                'absent' => $n - $participants,
                'participation' => $n ? $participants / $n : 0.0,
                'choices' => $total,
                'mutual_pairs' => $mutualPairs,
                'density' => $n > 1 ? $total / ($n * ($n - 1)) : 0.0,
                'reciprocity' => $total ? $mutualChoices / $total : 0.0,
                'cohesion' => $n > 1 ? $mutualPairs / ($n * ($n - 1) / 2) : 0.0,
                'mean_received' => $mean,
                'sd_received' => $sd,
                'no_received' => count(array_filter($students, fn ($s) => $s['received'] === 0)),
                'no_mutual' => count(array_filter($students, fn ($s) => $s['participated'] && $s['mutual'] === 0)),
            ],
        ];
    }

    /**
     * Subgrupos: conjuntos de alunos ligados entre si por escolhas RECÍPROCAS.
     *
     * @return array<int, int[]> grupos com 2+ alunos, do maior para o menor
     */
    public static function subgroups(array $analysis): array
    {
        $seen = [];
        $groups = [];
        foreach ($analysis['students'] as $id => $s) {
            if (isset($seen[$id]) || !$s['mutual_with']) {
                continue;
            }
            $stack = [$id];
            $group = [];
            while ($stack) {
                $cur = array_pop($stack);
                if (isset($seen[$cur])) {
                    continue;
                }
                $seen[$cur] = true;
                $group[] = $cur;
                foreach ($analysis['students'][$cur]['mutual_with'] as $nb) {
                    if (!isset($seen[$nb])) {
                        $stack[] = $nb;
                    }
                }
            }
            $groups[] = $group;
        }
        usort($groups, fn ($a, $b) => count($b) <=> count($a));

        return $groups;
    }

    /**
     * Pontos de atenção para OBSERVAÇÃO do professor, cruzando os critérios.
     * Cada item traz os fatos que o motivaram; a interpretação é humana.
     *
     * @param  array<int, array> $byCriterion análises indexadas por id do critério
     * @return array<int, array{student: array, reasons: string[], score: int}>
     */
    public static function attentionPoints(array $byCriterion): array
    {
        $criteria = self::criteria();
        $items = [];
        foreach ($byCriterion as $critId => $an) {
            $c = $criteria[$critId];
            foreach ($an['students'] as $id => $s) {
                $items[$id] ??= ['student' => $s, 'reasons' => [], 'score' => 0];
                if ((int) $c['polarity'] > 0) {
                    if ($s['received'] === 0) {
                        $items[$id]['reasons'][] = "Não recebeu indicações no critério {$c['label']}";
                        $items[$id]['score'] += 3;
                    } elseif ($s['band'] === 'poucas') {
                        $items[$id]['reasons'][] = "Poucas indicações no critério {$c['label']} ({$s['received']})";
                        $items[$id]['score'] += 1;
                    }
                    if ($s['participated'] && $s['mutual'] === 0) {
                        $items[$id]['reasons'][] = "Nenhuma escolha recíproca no critério {$c['label']}";
                        $items[$id]['score'] += 2;
                    }
                } elseif ($s['received'] > $an['metrics']['mean_received'] + 1.5 * $an['metrics']['sd_received'] && $s['received'] >= 3) {
                    $items[$id]['reasons'][] = "Citado por {$s['received']} colegas no critério {$c['label']}";
                    $items[$id]['score'] += 2;
                }
            }
        }
        foreach ($items as $id => $it) {
            if (!$it['student']['participated']) {
                $items[$id]['reasons'][] = 'Não respondeu ao questionário (dados de saída ausentes)';
            }
        }
        $items = array_filter($items, fn ($it) => $it['score'] >= 5);
        usort($items, fn ($a, $b) => $b['score'] <=> $a['score'] ?: strcmp($a['student']['name'], $b['student']['name']));

        return $items;
    }

    /**
     * Visão resumida da turma: última aplicação encerrada, a anterior e as análises.
     *
     * @return array{latest: ?array, previous: ?array, now: array<int, array>, before: array<int, array>, attention: array}
     */
    public static function overview(int $classId): array
    {
        static $cache = [];
        if (isset($cache[$classId])) {
            return $cache[$classId];
        }
        $apps = self::applications($classId, true);
        $latest = $apps ? $apps[count($apps) - 1] : null;
        $previous = count($apps) > 1 ? $apps[count($apps) - 2] : null;
        $now = $before = [];
        foreach (self::criteria() as $critId => $_) {
            if ($latest) {
                $now[$critId] = self::analyze((int) $latest['id'], $critId);
            }
            if ($previous) {
                $before[$critId] = self::analyze((int) $previous['id'], $critId);
            }
        }

        return $cache[$classId] = [
            'latest' => $latest, 'previous' => $previous, 'now' => $now, 'before' => $before,
            'attention' => $now ? self::attentionPoints($now) : [],
        ];
    }

    /** Aplicações da turma, da mais antiga para a mais recente. */
    public static function applications(int $classId, bool $onlyClosed = false): array
    {
        return Database::all(
            'SELECT * FROM applications WHERE class_id = ?'.($onlyClosed ? " AND status = 'closed'" : '').' ORDER BY applied_on',
            [$classId],
        );
    }

    /**
     * Posições do sociograma (algoritmo de Fruchterman-Reingold, determinístico).
     *
     * @return array<int, array{0: float, 1: float}> coordenadas em [0,1]
     */
    public static function layout(array $analysis, int $iterations = 300): array
    {
        $all = array_keys($analysis['students']);
        if (!$all) {
            return [];
        }
        // Alunos sem nenhuma ligação ficam fora da simulação (senão "fogem" e
        // comprimem o resto); são alinhados depois, na base do desenho.
        $linked = [];
        foreach ($analysis['edges'] as [$a, $b]) {
            $linked[$a] = $linked[$b] = true;
        }
        $ids = array_values(array_filter($all, fn ($id) => isset($linked[$id])));
        $loose = array_values(array_filter($all, fn ($id) => !isset($linked[$id])));
        $n = count($ids);
        $pos = [];
        foreach ($ids as $k => $id) {   // início em círculo: mesmo dado => mesmo desenho
            $ang = 2 * M_PI * $k / max(1, $n);
            $pos[$id] = [0.5 + 0.4 * cos($ang), 0.5 + 0.4 * sin($ang)];
        }
        $kk = 0.95 * sqrt(1 / max(1, $n));
        $temp = 0.12;
        for ($it = 0; $it < $iterations && $n > 1; $it++) {
            $disp = array_fill_keys($ids, [0.0, 0.0]);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $ids[$i];
                    $b = $ids[$j];
                    $dx = $pos[$a][0] - $pos[$b][0];
                    $dy = $pos[$a][1] - $pos[$b][1];
                    $d = max(0.01, sqrt($dx * $dx + $dy * $dy));
                    $f = $kk * $kk / $d;
                    $disp[$a][0] += $dx / $d * $f;
                    $disp[$a][1] += $dy / $d * $f;
                    $disp[$b][0] -= $dx / $d * $f;
                    $disp[$b][1] -= $dy / $d * $f;
                }
            }
            foreach ($analysis['edges'] as [$a, $b, $mutual]) {
                $dx = $pos[$a][0] - $pos[$b][0];
                $dy = $pos[$a][1] - $pos[$b][1];
                $d = max(0.01, sqrt($dx * $dx + $dy * $dy));
                $f = $d * $d / $kk * ($mutual ? 0.55 : 0.3);
                $disp[$a][0] -= $dx / $d * $f;
                $disp[$a][1] -= $dy / $d * $f;
                $disp[$b][0] += $dx / $d * $f;
                $disp[$b][1] += $dy / $d * $f;
            }
            foreach ($ids as $id) {   // atração ao centro mantém componentes soltos por perto
                $disp[$id][0] += (0.5 - $pos[$id][0]) * 0.09;
                $disp[$id][1] += (0.5 - $pos[$id][1]) * 0.09;
                $d = max(0.0001, sqrt($disp[$id][0] ** 2 + $disp[$id][1] ** 2));
                $pos[$id][0] += $disp[$id][0] / $d * min($d, $temp);
                $pos[$id][1] += $disp[$id][1] / $d * min($d, $temp);
            }
            $temp *= 0.985;
        }

        $normalize = function (array $pos): array {
            if (!$pos) {
                return $pos;
            }
            $xs = array_column($pos, 0);
            $ys = array_column($pos, 1);
            [$minX, $maxX, $minY, $maxY] = [min($xs), max($xs), min($ys), max($ys)];
            foreach ($pos as $id => [$x, $y]) {
                $pos[$id] = [$maxX > $minX ? ($x - $minX) / ($maxX - $minX) : 0.5, $maxY > $minY ? ($y - $minY) / ($maxY - $minY) : 0.5];
            }

            return $pos;
        };
        $pos = $normalize($pos);

        // Afasta nós que ficaram sobrepostos (distância mínima entre centros).
        $min = min(0.105, 0.62 / sqrt(max(1, $n)));
        for ($pass = 0; $pass < 80; $pass++) {
            $moved = false;
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $ids[$i];
                    $b = $ids[$j];
                    $dx = $pos[$a][0] - $pos[$b][0];
                    $dy = $pos[$a][1] - $pos[$b][1];
                    $d = sqrt($dx * $dx + $dy * $dy);
                    if ($d >= $min) {
                        continue;
                    }
                    if ($d < 0.0001) {
                        $dx = 0.01 * (($i % 2) ? 1 : -1);
                        $dy = 0.01;
                        $d = sqrt($dx * $dx + $dy * $dy);
                    }
                    $push = ($min - $d) / 2;
                    $pos[$a][0] += $dx / $d * $push;
                    $pos[$a][1] += $dy / $d * $push;
                    $pos[$b][0] -= $dx / $d * $push;
                    $pos[$b][1] -= $dy / $d * $push;
                    $moved = true;
                }
            }
            if (!$moved) {
                break;
            }
        }
        $pos = $normalize($pos);

        if ($loose) {   // reserva a faixa inferior para quem não tem ligações
            foreach ($pos as $id => [$x, $y]) {
                $pos[$id] = [$x, $y * 0.86];
            }
            foreach ($loose as $k => $id) {
                $pos[$id] = [0.04 + 0.075 * $k, 1.0];
            }
        }

        return $pos;
    }

    /**
     * Sugestão de grupos de trabalho. Procura, para cada aluno, um grupo com
     * alguém que ele escolheu, e evita juntar pares com indicação de dificuldade.
     *
     * @return array{groups: array<int, int[]>, served: array<int, bool>, conflicts: int}
     */
    public static function suggestGroups(array $positive, array $negative, int $size): array
    {
        $ids = array_keys($positive['students']);
        $n = count($ids);
        $k = max(1, (int) ceil($n / max(2, $size)));
        $score = function (int $a, int $b) use ($positive, $negative): float {
            $s = 0.0;
            $ab = isset($positive['matrix'][$a][$b]);
            $ba = isset($positive['matrix'][$b][$a]);
            $s += $ab && $ba ? 3 : (($ab || $ba) ? 1.2 : 0);
            if (isset($negative['matrix'][$a][$b]) || isset($negative['matrix'][$b][$a])) {
                $s -= 6;
            }

            return $s;
        };

        // Quem tem menos indicações é alocado primeiro, para ter mais opções.
        usort($ids, fn ($a, $b) => $positive['students'][$a]['received'] <=> $positive['students'][$b]['received'] ?: $a <=> $b);
        $groups = array_fill(0, $k, []);
        $cap = (int) ceil($n / $k);
        foreach ($ids as $id) {
            $best = null;
            $bestScore = -INF;
            foreach ($groups as $g => $members) {
                if (count($members) >= $cap) {
                    continue;
                }
                $sc = -0.15 * count($members);   // desempate: grupos menores
                foreach ($members as $m) {
                    $sc += $score($id, $m);
                }
                if ($sc > $bestScore) {
                    $bestScore = $sc;
                    $best = $g;
                }
            }
            $groups[$best][] = $id;
        }

        // Melhoria local: troca pares de alunos entre grupos enquanto o total melhorar.
        $groupScore = function (array $members) use ($score): float {
            $t = 0.0;
            $c = count($members);
            for ($i = 0; $i < $c; $i++) {
                for ($j = $i + 1; $j < $c; $j++) {
                    $t += $score($members[$i], $members[$j]);
                }
            }

            return $t;
        };
        for ($pass = 0; $pass < 6; $pass++) {
            $improved = false;
            for ($g1 = 0; $g1 < $k; $g1++) {
                for ($g2 = $g1 + 1; $g2 < $k; $g2++) {
                    foreach ($groups[$g1] as $i => $a) {
                        foreach ($groups[$g2] as $j => $b) {
                            $before = $groupScore($groups[$g1]) + $groupScore($groups[$g2]);
                            [$groups[$g1][$i], $groups[$g2][$j]] = [$b, $a];
                            if ($groupScore($groups[$g1]) + $groupScore($groups[$g2]) > $before + 0.001) {
                                $improved = true;
                                $a = $b;
                            } else {
                                [$groups[$g1][$i], $groups[$g2][$j]] = [$a, $b];
                            }
                        }
                    }
                }
            }
            if (!$improved) {
                break;
            }
        }

        $served = [];
        $conflicts = 0;
        foreach ($groups as $members) {
            foreach ($members as $a) {
                $served[$a] = false;
                foreach ($members as $b) {
                    if ($a !== $b && isset($positive['matrix'][$a][$b])) {
                        $served[$a] = true;
                    }
                    if ($a < $b && (isset($negative['matrix'][$a][$b]) || isset($negative['matrix'][$b][$a]))) {
                        $conflicts++;
                    }
                }
            }
        }

        return ['groups' => $groups, 'served' => $served, 'conflicts' => $conflicts];
    }
}
