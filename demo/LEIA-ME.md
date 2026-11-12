# Sociometria Escolar — modelo de demonstração

Sistema em **PHP puro + MySQL**, com **dados 100% fictícios**, para apresentar o
produto. Não usa Composer, Node nem bibliotecas externas: basta enviar os
arquivos e importar dois arquivos SQL. Funciona em PHP 8.1 ou superior e
MySQL 5.7 ou superior.

> Este modelo é para demonstração. Não cadastre dados reais de alunos nele.

## O que tem

| Área | O que mostra |
|---|---|
| Painel | Situação de todas as turmas, evolução entre aplicações e por onde começar a olhar |
| Turma | Sociograma interativo, matriz sociométrica, indicadores por aluno e subgrupos, por pergunta e por aplicação |
| Aluno | Ficha individual com escolhas, reciprocidades, evolução e intervenções |
| Comparar | Duas aplicações lado a lado, com o que mudou e quem mais mudou |
| Grupos | Sugestão de grupos de trabalho a partir das escolhas |
| Relatórios | Institucional, da turma, de atenção pedagógica, individual, comparativo — todos com versão para impressão/PDF — e planilhas CSV |
| Intervenções | Plano de ação com responsável, prazo, situação e resultado observado |
| Aplicação | Códigos individuais dos alunos e acompanhamento das respostas |
| Responder | Formulário do aluno, com código de uso único |
| Metodologia | Como cada indicador é calculado e os limites da interpretação |

## Acessos de demonstração

| Perfil | E-mail | Senha |
|---|---|---|
| Gestão (todas as turmas) | `gestao@demo.sociometria` | `demo1234` |
| Docente (somente o 5º Ano A) | `professora@demo.sociometria` | `demo1234` |

Códigos de aluno para testar o formulário: arquivo `sql/codigos_exemplo.txt`
(cada código funciona uma única vez; reimportar `dados_demo.sql` restaura todos).

## Como publicar (cPanel, sem terminal)

1. **Banco de dados** — cPanel → *Bancos de dados MySQL*:
   - crie o banco `sociometria_demo` (o cPanel acrescenta o prefixo da conta);
   - crie o usuário `socdemo` com uma senha forte gerada pelo próprio cPanel;
   - adicione o usuário ao banco marcando apenas **SELECT, INSERT, UPDATE, DELETE**.
2. **Tabelas e dados** — cPanel → *phpMyAdmin* → selecione o banco novo → aba
   *Importar*: primeiro `sql/schema.sql`, depois `sql/dados_demo.sql`.
   - A importação precisa de um usuário com permissão de criar tabelas: o
     phpMyAdmin aberto pelo cPanel já tem.
3. **Arquivos** — cPanel → *Gerenciador de Arquivos*: crie a pasta
   `sociometria_demo` na pasta inicial da conta (**fora** de `public_html`),
   envie o `.zip` para dentro dela e use *Extrair*.
4. **Configuração** — dentro de `sociometria_demo`, copie `.env.example` para
   `.env` e preencha `DB_NAME`, `DB_USER`, `DB_PASS` e `APP_URL`.
5. **Subdomínio** — cPanel → *Domínios* → *Criar um novo domínio*: informe o
   subdomínio e, em *Raiz do documento*, aponte para `sociometria_demo/public`.
   Só a pasta `public` fica acessível pela internet.
6. **PHP** — cPanel → *MultiPHP Manager*: confirme PHP 8.1 ou superior **apenas
   para o subdomínio novo**. Não altere o padrão da conta.
7. **HTTPS** — cPanel → *SSL/TLS Status* → execute o AutoSSL para o subdomínio.
8. Acesse o endereço e entre com o acesso de Gestão.

Nenhum outro site, banco ou configuração da conta é alterado por esses passos.

## Como desfazer

Remova o subdomínio, apague a pasta `sociometria_demo` e exclua o banco e o
usuário criados no passo 1.

## Estrutura

```
public/            única pasta publicada (index.php, assets, .htaccess)
app/               configuração, banco, motor sociométrico, páginas
sql/               schema.sql, dados_demo.sql, codigos_exemplo.txt
tools/             gerador dos dados fictícios (uso em desenvolvimento)
.env.example       modelo de configuração
```

## Limites deste modelo

- Relatórios em PDF são gerados pela impressão do navegador (*Salvar como PDF*).
- O login tem apenas um limite simples de tentativas; não há segundo fator.
- Todas as instituições fictícias ficam em um único banco. O isolamento por
  instituição, a criptografia dos dados dos alunos e a auditoria completa
  estão na versão de produção (pasta raiz do repositório), não aqui.
- O cadastro de escolas, turmas e alunos é feito pelos dados de exemplo; não há
  telas de cadastro.
