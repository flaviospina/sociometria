<?php
// demo/tools/gerar_dados_demo.php
// Gera sql/dados_demo.sql com dados 100% FICTÍCIOS e determinísticos.
// Uso (somente no ambiente de desenvolvimento):  php tools/gerar_dados_demo.php
// No servidor basta importar o arquivo .sql pelo phpMyAdmin.

mt_srand(20261112);

$first = ['Ana','Bruno','Camila','Daniel','Eduarda','Felipe','Gabriela','Heitor','Isabela','João','Karina','Lucas','Mariana','Nicolas','Olívia','Pedro','Rafaela','Samuel','Tainá','Vitor','Yasmin','Arthur','Beatriz','Caio','Davi','Elisa','Fernando','Giovana','Henrique','Júlia','Kauã','Larissa','Miguel','Natália','Otávio','Paula','Rodrigo','Sofia','Thiago','Valentina','Alice','Bernardo','Clara','Enzo','Lívia','Matheus','Lorena','Gustavo','Helena','Igor'];
$last = ['Almeida','Barbosa','Cardoso','Dias','Esteves','Ferreira','Gomes','Lima','Martins','Nogueira','Oliveira','Pereira','Queiroz','Ribeiro','Santos','Teixeira','Vieira','Xavier','Araújo','Batista','Campos','Duarte','Farias','Guimarães','Moreira','Pacheco','Rocha','Silva','Tavares','Mendes'];

$schools = [[1, 'Escola Municipal Jardim das Acácias', 'Cidade Modelo'], [2, 'Colégio Horizonte Azul', 'Cidade Modelo']];
$classes = [
    [1, 1, '5º Ano A', '5º ano', 'Prof.ª Helena Duarte', 28],
    [2, 1, '5º Ano B', '5º ano', 'Prof. Marcos Tavares', 26],
    [3, 1, '6º Ano A', '6º ano', 'Prof.ª Renata Campos', 30],
    [4, 2, '4º Ano A', '4º ano', 'Prof.ª Lúcia Moreira', 24],
    [5, 2, '6º Ano B', '6º ano', 'Prof. André Pacheco', 27],
];
$criteria = [
    [1, 'estudo', 'Estudo', 'Com quem você gostaria de fazer um trabalho em grupo?', 1, 3],
    [2, 'recreio', 'Recreio', 'Com quem você gosta de ficar no recreio?', 1, 3],
    [3, 'dificuldade', 'Dificuldade', 'Com quem você teria mais dificuldade para trabalhar em grupo?', -1, 2],
];

function q(?string $v): string { return $v === null ? 'NULL' : "'".str_replace(["\\", "'"], ["\\\\", "''"], $v)."'"; }
function frand(): float { return mt_rand() / mt_getrandmax(); }
function weightedPick(array $weights): int {
    $sum = array_sum($weights); $r = frand() * $sum;
    foreach ($weights as $k => $w) { $r -= $w; if ($r <= 0) return $k; }
    return array_key_last($weights);
}
function code(array &$used): string {
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do { $c = ''; for ($i = 0; $i < 6; $i++) $c .= $abc[mt_rand(0, 31)]; } while (isset($used[$c]));
    $used[$c] = true; return $c;
}

$out = ["-- demo/sql/dados_demo.sql — DADOS FICTÍCIOS gerados por tools/gerar_dados_demo.php", "-- Importar DEPOIS do schema.sql. Apaga e recria todo o conteúdo de demonstração.", 'SET NAMES utf8mb4;'];
foreach (['audit_log','interventions','choices','participations','applications','criteria','students','user_classes','classes','schools','users'] as $t) $out[] = "DELETE FROM $t;";   // ordem: tabelas dependentes primeiro (TRUNCATE falha com chaves estrangeiras)

// Senha de demonstração (pública): demo1234
$hash = password_hash('demo1234', PASSWORD_BCRYPT, ['cost' => 10]);
$out[] = "INSERT INTO users (id,name,email,password_hash,role) VALUES (1,'Coordenação Pedagógica','gestao@demo.sociometria',".q($hash).",'admin'),(2,'Prof.ª Helena Duarte','professora@demo.sociometria',".q($hash).",'teacher');";
foreach ($schools as $s) $out[] = "INSERT INTO schools (id,name,city) VALUES ($s[0],".q($s[1]).','.q($s[2]).');';
foreach ($classes as $c) $out[] = "INSERT INTO classes (id,school_id,name,grade,school_year,teacher_name) VALUES ($c[0],$c[1],".q($c[2]).','.q($c[3]).',2026,'.q($c[4]).');';
$out[] = 'INSERT INTO user_classes (user_id,class_id) VALUES (2,1);';
foreach ($criteria as $i => $c) $out[] = "INSERT INTO criteria (id,code,label,question,polarity,max_choices,sort_order) VALUES ($c[0],".q($c[1]).','.q($c[2]).','.q($c[3]).",$c[4],$c[5],".($i + 1).');';

$studentId = 0; $appId = 0; $usedCodes = []; $usedNames = []; $rows = ['students' => [], 'applications' => [], 'participations' => [], 'choices' => []];
$sampleCodes = [];

foreach ($classes as [$classId, , $className, , , $n]) {
    // --- alunos ---
    $ids = [];
    $names = [];
    while (count($names) < $n) {
        $nm = $first[mt_rand(0, count($first) - 1)].' '.$last[mt_rand(0, count($last) - 1)];
        if (!isset($usedNames[$nm])) { $usedNames[$nm] = true; $names[] = $nm; }
    }
    sort($names, SORT_LOCALE_STRING);
    foreach ($names as $k => $nm) { $ids[$k] = ++$studentId; $rows['students'][] = "($studentId,$classId,".q($nm).','.($k + 1).')'; }

    // --- estrutura social latente: grupos de afinidade + alguns alunos com poucos vínculos ---
    $groupCount = max(4, (int) round($n / 5.5));
    $group = []; for ($i = 0; $i < $n; $i++) $group[$i] = $i % $groupCount;
    shuffle($group);
    $pop = []; for ($i = 0; $i < $n; $i++) $pop[$i] = 0.85 + frand() * 0.5;
    $fringe = (array) array_rand(array_flip(range(0, $n - 1)), 3);   // 3 alunos com poucos vínculos
    foreach ($fringe as $f) $pop[$f] = 0.1;
    $star = array_rand($pop); if (!in_array($star, $fringe, true)) $pop[$star] = 2.2;

    $aff = [];
    for ($i = 0; $i < $n; $i++) for ($j = $i + 1; $j < $n; $j++) {
        $base = ($group[$i] === $group[$j] ? 14.0 : 0.45) * (0.6 + frand() * 0.8);
        if (frand() < 0.05) $base = 9.0;   // amizades entre grupos
        $aff[$i][$j] = $aff[$j][$i] = $base;
    }
    $tension = [];   // pares com atrito
    for ($t = 0; $t < (int) ($n / 4); $t++) { $a = mt_rand(0, $n - 1); $b = mt_rand(0, $n - 1); if ($a !== $b && $group[$a] !== $group[$b]) { $tension["$a-$b"] = $tension["$b-$a"] = true; } }

    $waves = [['Diagnóstico inicial — Março', '2026-03-16', 'closed'], ['Reaplicação — Agosto', '2026-08-24', 'closed']];
    if ($classId === 1) $waves[] = ['Aplicação em andamento — Novembro', '2026-11-10', 'open'];

    foreach ($waves as $w => [$title, $date, $status]) {
        ++$appId;
        $rows['applications'][] = "($appId,$classId,".q($title).",'$date','$status')";

        // 2ª aplicação: vínculos mudam um pouco; na turma 1 a integração melhora (efeito das intervenções)
        if ($w >= 1) {
            for ($i = 0; $i < $n; $i++) for ($j = $i + 1; $j < $n; $j++) { $aff[$i][$j] = $aff[$j][$i] = $aff[$i][$j] * (0.85 + frand() * 0.3); }
            if ($classId === 1 || $classId === 4) {
                foreach ($fringe as $f) { $pop[$f] = 1.0; for ($k = 0; $k < 3; $k++) { $j = mt_rand(0, $n - 1); if ($j !== $f) $aff[$f][$j] = $aff[$j][$f] = 22.0; } }
                $tension = array_slice($tension, 0, (int) (count($tension) / 2), true);
            }
        }

        $absent = $status === 'open'
            ? array_flip((array) array_rand(array_flip(range(0, $n - 1)), (int) round($n * 0.4)))
            : array_flip((array) array_rand(array_flip(range(0, $n - 1)), mt_rand(1, 3)));

        for ($i = 0; $i < $n; $i++) {
            $c = code($usedCodes);
            $answered = !isset($absent[$i]);
            if ($status === 'open' && !$answered && count($sampleCodes) < 3) $sampleCodes[] = [$c, $names[$i], $className];
            $rows['participations'][] = "($appId,{$ids[$i]},'$c',".($answered ? "'$date 0".mt_rand(8, 9).':'.str_pad((string) mt_rand(0, 59), 2, '0', STR_PAD_LEFT).":00'" : 'NULL').')';
            if (!$answered) continue;

            foreach ($criteria as [$critId, $critCode, , , $polarity, $max]) {
                $weights = [];
                for ($j = 0; $j < $n; $j++) {
                    if ($j === $i) continue;
                    if ($polarity > 0) {
                        $wgt = $aff[$i][$j] * $pop[$j];
                        if ($critCode === 'recreio') $wgt *= ($group[$i] === $group[$j] ? 1.3 : 0.9) * (0.7 + frand() * 0.6);
                    } else {
                        $wgt = (isset($tension["$i-$j"]) ? 14 : 0.25) + ($group[$i] === $group[$j] ? 0 : 0.5) + ($pop[$j] < 0.2 ? 1.2 : 0);
                    }
                    $weights[$j] = $wgt;
                }
                $count = $polarity > 0 ? ($max) : (frand() < 0.45 ? 0 : mt_rand(1, $max));
                for ($r = 1; $r <= $count && $weights; $r++) {
                    $j = weightedPick($weights); unset($weights[$j]);
                    $rows['choices'][] = "($appId,$critId,{$ids[$i]},{$ids[$j]},$r)";
                }
            }
        }
    }
}

$cols = ['students' => 'id,class_id,name,call_number', 'applications' => 'id,class_id,title,applied_on,status', 'participations' => 'application_id,student_id,access_code,answered_at', 'choices' => 'application_id,criterion_id,chooser_id,chosen_id,choice_rank'];
foreach ($rows as $table => $list) foreach (array_chunk($list, 250) as $chunk) $out[] = "INSERT INTO $table ({$cols[$table]}) VALUES\n".implode(",\n", $chunk).';';

$out[] = "INSERT INTO interventions (class_id,student_id,title,description,category,status,owner_name,due_on,created_at) VALUES
(1,NULL,'Rodízio de grupos de trabalho','Formar grupos de 4 alunos com rodízio quinzenal, usando a sugestão de agrupamento do sistema para aproximar alunos com poucas indicações de colegas que eles escolheram.','Agrupamento','concluida','Prof.ª Helena Duarte','2026-05-29','2026-03-23 10:00:00'),
(1,NULL,'Jogos cooperativos no recreio','Uma vez por semana, atividade dirigida no recreio com equipes mistas definidas pela professora.','Convivência','em_andamento','Coordenação Pedagógica','2026-12-04','2026-04-06 09:30:00'),
(3,NULL,'Roda de conversa sobre convivência','Encontro mensal para combinados da turma e escuta dos alunos, após indicação de pares com dificuldade recíproca.','Mediação','planejada','Prof.ª Renata Campos','2026-11-27','2026-09-01 14:00:00'),
(4,NULL,'Tutoria entre pares em Matemática','Duplas de estudo formadas a partir das escolhas do critério Estudo.','Aprendizagem','em_andamento','Prof.ª Lúcia Moreira','2026-11-30','2026-04-13 08:00:00');";

file_put_contents(__DIR__.'/../sql/dados_demo.sql', implode("\n", $out)."\n");
file_put_contents(__DIR__.'/../sql/codigos_exemplo.txt', "Códigos de acesso de exemplo (aplicação em andamento, alunos que ainda não responderam):\n".implode("\n", array_map(fn ($s) => "$s[0]  —  $s[1] ($s[2])", $sampleCodes))."\n");
echo 'alunos: '.count($rows['students']).', aplicações: '.count($rows['applications']).', escolhas: '.count($rows['choices'])."\n";
