<?php
// demo/app/pages/metodologia.php — como os números são calculados e como devem ser lidos.

declare(strict_types=1);

page_start('Metodologia', 'metodologia');
page_head('Metodologia', 'Como os indicadores são calculados e como devem ser lidos.');
?>
<div class="grid g2">
  <div class="card">
    <h2>O instrumento</h2>
    <p>Cada aluno responde, de forma individual e reservada, a perguntas sobre com quais colegas prefere realizar atividades. As respostas nunca são mostradas a outros alunos.</p>
    <table>
      <thead><tr><th>Pergunta</th><th>Tipo</th><th class="num">Escolhas</th></tr></thead>
      <tbody>
      <?php foreach (Sociometry::criteria() as $c): ?>
        <tr><td><strong><?= e($c['label']) ?></strong><br><span class="small muted">“<?= e($c['question']) ?>”</span></td>
          <td class="small"><?= (int) $c['polarity'] > 0 ? 'Aproximação' : 'Dificuldade' ?></td><td class="num">até <?= (int) $c['max_choices'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card">
    <h2>Limites da interpretação</h2>
    <ul class="reasons">
      <li>O resultado descreve as escolhas feitas <strong>neste questionário e nesta data</strong>.</li>
      <li>“Sem indicações recebidas” <strong>não significa</strong> rejeição, isolamento ou qualquer condição do aluno.</li>
      <li>Alunos que não responderam aparecem identificados; a ausência de escolhas deles é falta de dado, não um resultado.</li>
      <li>O sistema não produz diagnóstico. A leitura e a decisão são sempre do professor e da equipe pedagógica.</li>
      <li>Não há ranking público: fichas individuais são de acesso restrito e cada consulta fica registrada.</li>
    </ul>
  </div>
</div>
<div class="card">
  <h2>Indicadores</h2>
  <table>
    <thead><tr><th>Indicador</th><th>Cálculo</th><th>Para que serve</th></tr></thead>
    <tbody>
      <tr><td><strong>Participação</strong></td><td>alunos que responderam ÷ alunos elegíveis</td><td>Dá a confiança da leitura; abaixo de 80% convém reaplicar.</td></tr>
      <tr><td><strong>Indicações recebidas</strong></td><td>quantos colegas escolheram o aluno na pergunta</td><td>Mostra quem é mais e menos lembrado em cada situação.</td></tr>
      <tr><td><strong>Escolha recíproca</strong></td><td>A escolheu B e B escolheu A na mesma pergunta</td><td>Indica vínculos correspondidos.</td></tr>
      <tr><td><strong>Reciprocidade</strong></td><td>escolhas que fazem parte de um par recíproco ÷ escolhas feitas</td><td>Quanto das preferências é correspondido na turma.</td></tr>
      <tr><td><strong>Densidade</strong></td><td>escolhas feitas ÷ (N × (N − 1))</td><td>Quanto da rede possível foi usada (limitada pelo máximo de escolhas).</td></tr>
      <tr><td><strong>Coesão</strong></td><td>pares recíprocos ÷ pares possíveis (N × (N − 1) ÷ 2)</td><td>Compara turmas e aplicações de tamanhos parecidos.</td></tr>
      <tr><td><strong>Faixa de indicações</strong></td><td>comparação com a média ± 1 desvio-padrão da própria turma</td><td>Situa o aluno em relação à turma, não a um padrão externo.</td></tr>
      <tr><td><strong>Subgrupos</strong></td><td>alunos ligados entre si por escolhas recíprocas</td><td>Ajuda a perceber grupos fechados e planejar agrupamentos.</td></tr>
      <tr><td><strong>Pontos de atenção</strong></td><td>soma de sinais: sem indicações (3), sem recíproca (2), poucas indicações (1), muito citado em Dificuldade (2); entra na lista com 5 ou mais (ex.: sem indicações e sem recíproca na mesma pergunta)</td><td>Ordena por onde começar a observar.</td></tr>
    </tbody>
  </table>
</div>
<?php page_end();
