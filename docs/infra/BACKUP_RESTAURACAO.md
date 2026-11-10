# Backup e restauração

## Estado

**Estratégia projetada. Nenhum backup está configurado, nenhum script de backup
existe no repositório (previsto para as Etapas 9 e 10) e nenhuma restauração
foi testada.** A VPS ainda não foi diagnosticada.

| Já existe no código | É plano |
|---|---|
| Um banco por instituição, com nome opaco em `institutions.db_name` (banco de controle) | Rotina de dump, cópia externa cifrada, retenção |
| `institutions.schema_version` (última migration aplicada no tenant) | Manifesto por backup e teste de restauração |
| `db_password` e `data_key` de cada instituição cifrados com a `APP_KEY` | Custódia da `APP_KEY` fora do servidor |
| Auditoria com cadeia de hash (`AuditLogger::verifyChain()`, sem comando artisan ainda) | Evento de auditoria para restauração |

**Não presumir que a HostGator faz backup.** Verificar no Portal do Cliente o
que o plano inclui; mesmo que inclua, um backup do provedor que nunca foi
restaurado por nós não conta como cópia. Contratar storage externo depende de
autorização do responsável.

## Metas preliminares do piloto

São **metas a validar**, não garantias. Só passam a valer depois de um teste de
restauração cronometrado.

| Meta | Valor | Observação |
|---|---|---|
| RPO (perda máxima de dados) | 4 h | Exige dump a cada 4 h **ou** log binário para recuperação pontual (PITR). Validar o custo de dumps tão frequentes na VPS |
| RTO (tempo para voltar) | 8 h | Inclui recriar o servidor do zero; depende do provedor e de o procedimento estar ensaiado |
| Retenção | 30 dias | Rever com as exigências de LGPD e contrato de cada instituição |
| Teste de restauração | Trimestral | Com registro de data, duração e resultado |

## O que entra no backup

| Item | Conteúdo | Observação |
|---|---|---|
| Banco de controle | Instituições, usuários, vínculos, auditoria; também sessões, cache e fila | Contém as credenciais e chaves dos tenants **cifradas** |
| Cada banco de tenant | Dados institucionais e de alunos | Um arquivo **por banco**; nunca um dump único de tudo |
| Contas e GRANTs do banco | Usuários `soc_u_*` e privilégios | O dump de um banco não leva os usuários |
| `shared/.env` e `APP_KEY` | Segredos | **Local separado dos dados** (ver abaixo) |
| `shared/storage` | Arquivos enviados/gerados, se houver | Logs não precisam ir para a cópia externa |
| Código | — | Não precisa: está no Git e nos artefatos de release |

### `APP_KEY`: sem ela, os backups dos tenants não servem

A `APP_KEY` decifra `data_key` (campos cifrados de alunos) e `db_password`.
Dump de tenant restaurado sem a `APP_KEY` correta deixa os campos cifrados
ilegíveis para sempre.

- [ ] Guardar a `APP_KEY` e uma cópia do `.env` em **dois** locais fora da VPS (cofre de senhas do responsável + cópia offline lacrada).
- [ ] **Nunca** no mesmo repositório/bucket dos dumps: quem obtiver os dois lê tudo.
- [ ] Conferir a cópia a cada teste de restauração.
- [ ] Guardar junto a senha/chave de cifragem dos backups (restic/age), pelo mesmo motivo.

## Estratégia 3-2-1

- **3 cópias**: produção + cópia local na VPS + cópia externa.
- **2 mídias/sistemas**: disco da VPS e storage de objetos.
- **1 fora do provedor**: storage S3-compatível de outra empresa, **cifrado no cliente** antes do envio.

Ferramentas candidatas (confirmar no diagnóstico o que já existe): `restic`
(cifra, deduplica e aplica retenção) ou `rclone` + `age`/`gpg`. Instalar apenas
dos repositórios oficiais da distribuição ou do binário oficial com soma
conferida. No cenário B, o backup do cPanel/JetBackup pode complementar, mas
**não substitui**: bancos criados pelo provisionador via SQL podem não pertencer
à conta cPanel e ficar fora do backup do painel.

## Dump por banco

Conta dedicada somente leitura (nunca root, nunca as contas da aplicação):

```sql
-- EXEMPLO — confirmar após o diagnóstico (prefixos conforme TENANT_DB_PREFIX)
CREATE USER 'soc_backup'@'localhost' IDENTIFIED BY '<gerada no servidor>';
GRANT SELECT, SHOW VIEW, TRIGGER, EVENT, LOCK TABLES ON `sociometria\_control`.* TO 'soc_backup'@'localhost';
GRANT SELECT, SHOW VIEW, TRIGGER, EVENT, LOCK TABLES ON `soc\_t\_%`.*            TO 'soc_backup'@'localhost';
```

```bash
# EXEMPLO do que o script fará — confirmar após o diagnóstico
# Credencial em arquivo 600 do usuário de backup; nunca na linha de comando.
DUMP="mariadb-dump --defaults-extra-file=$HOME/.backup.cnf"   # MySQL 8: mysqldump (acrescentar --no-tablespaces)
SQL="mariadb --defaults-extra-file=$HOME/.backup.cnf -N -B"
DEST="/var/backups/sociometria/$(date -u +%FT%H%MZ)"; umask 077; mkdir -p "$DEST"

$DUMP --single-transaction --routines --triggers sociometria_control | gzip > "$DEST/control.sql.gz"

$SQL -e "SELECT public_id, db_name, IFNULL(schema_version,'') FROM sociometria_control.institutions" |
while read -r PUBLIC_ID DB VERSION; do
  $DUMP --single-transaction --routines --triggers "$DB" | gzip > "$DEST/$DB.sql.gz"
  echo "$PUBLIC_ID $DB $VERSION" >> "$DEST/MANIFESTO.txt"
done
( cd "$DEST" && sha256sum *.sql.gz > SHA256SUMS )

restic backup "$DEST" && restic forget --keep-within 30d --prune   # cópia externa cifrada + retenção
```

- `--single-transaction` dá leitura consistente por banco sem travar (InnoDB). O controle e os tenants são capturados em instantes ligeiramente diferentes: guardar sempre o conjunto **da mesma execução**.
- O manifesto liga `public_id` ↔ `db_name` ↔ `schema_version` no momento do backup. Ele não contém nomes de instituições nem de alunos.
- Contas e GRANTs: MariaDB `mariadb-dump --system=users`; MySQL 8: definir o método no diagnóstico.
- Falha em qualquer banco = backup **falho**; o script deve sair com erro e alertar (ver `MONITORAMENTO.md`).
- Para RPO de 4 h sem dump a cada 4 h: habilitar log binário e guardar os binlogs junto do backup. Só adotar depois de ensaiar a recuperação pontual.

## Restauração por instituição

Regras:

1. **Sempre** primeiro em banco temporário isolado. Nunca direto sobre produção.
2. **Nunca** restaurar o dump de um tenant no banco de outro. Conferir o `db_name` do arquivo contra a instituição no banco de controle.
3. Sobrescrever produção só com plano aprovado por escrito e reversão preparada.
4. Toda restauração é autorizada, registrada e auditada.

Roteiro:

- [ ] **Autorização**: pedido formal da instituição, identidade do solicitante validada, aprovação do responsável pela plataforma. Registrar quem, quando e por quê.
- [ ] **Identificar**: obter `public_id` no painel administrativo; no controle, `SELECT public_id, db_name, schema_version, status FROM institutions WHERE public_id = '...'`. Conferir com a linha do `MANIFESTO.txt` do backup escolhido.
- [ ] **Integridade**: `sha256sum -c SHA256SUMS` e `gzip -t ARQUIVO`.
- [ ] **Restaurar em banco temporário** (operador humano, via socket local):

  ```bash
  # EXEMPLO — confirmar após o diagnóstico
  mariadb -e "CREATE DATABASE soc_restore_AAAAMMDD CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
  gunzip -c soc_t_XXXX.sql.gz | mariadb soc_restore_AAAAMMDD
  mariadb -N -e "SELECT migration FROM soc_restore_AAAAMMDD.migrations ORDER BY id DESC LIMIT 1"
  ```

- [ ] **Conferir `schema_version`**: a última migration do banco restaurado deve ser igual à do manifesto. Se for mais antiga que a versão atual do código, planejar `tenants:migrate --institution=` após a troca.
- [ ] **Validar conteúdo**: contagens por tabela e amostra conferida com a instituição, sem copiar dados de alunos para chamados, e-mails ou logs.
- [ ] **Decidir** com a instituição: extrair apenas os registros necessários (preferível) ou substituir o banco inteiro.
- [ ] **Se substituir**: suspender a instituição (bloqueia acesso e jobs), fazer dump do estado atual (é a reversão), trocar o conteúdo, rodar `tenants:migrate --institution=PUBLIC_ID --force` se preciso, reativar, validar com a instituição.
- [ ] **Auditoria**: registrar a operação. Ainda não há evento próprio no código (proposta: `tenant.restored`); até existir, registro manual assinado pelo responsável.
- [ ] **Limpeza**: `DROP DATABASE soc_restore_AAAAMMDD` e apagar os arquivos extraídos.

Atenção: os campos cifrados só abrem com a `data_key` que a instituição tinha
quando o dump foi feito. Se a instituição foi reprovisionada depois, usar o dump
do controle da mesma execução para recuperar a chave correspondente.

## Restauração completa (perda da VPS)

1. Nova VPS: hardening, servidor web e banco conforme os demais documentos.
2. Recuperar `.env`/`APP_KEY` da custódia separada. **Não gerar chave nova.**
3. Restaurar o controle, depois contas e GRANTs, depois cada tenant no banco de **mesmo** `db_name`.
4. Publicar a release correspondente; `php artisan migrate:status`; `/healthz`.
5. Verificar a cadeia de auditoria (`verifyChain()`), login com MFA e acesso de uma instituição de teste.
6. Só então apontar o DNS. Comunicar às instituições a janela de dados perdida.

Lacuna conhecida: se as contas `soc_u_*` não forem restauradas, não existe hoje
comando para recriá-las a partir do banco de controle.

## Registro de testes de restauração

| Data | Backup usado | Escopo (controle / 1 tenant / completo) | Duração | Resultado | Responsável |
|---|---|---|---|---|---|
| _nenhum teste realizado_ | | | | | |
