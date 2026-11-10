# Deploy e rollback

## Estado

**Procedimento projetado. Nenhum deploy foi feito e nenhum script de deploy
existe no repositório** (previsto para as Etapas 9 e 10). A VPS ainda não foi
diagnosticada.

| Já existe | É plano |
|---|---|
| `.github/workflows/ci.yml` — **ainda não executado no GitHub** | Empacotamento da release e publicação |
| `php artisan tenants:migrate [--institution=] [--force]`: um banco de tenant por vez; exige `--force` em produção; uma falha não interrompe as demais; audita `tenant.migrated` | Script de deploy/rollback no servidor |
| `GET /healthz` → `{"status":"ok"}` ou 503 | Smoke tests e acompanhamento pós-deploy |
| Testes de isolamento multi-tenant em `tests/Feature/Tenancy` | Validação de backup antes do deploy |

Comandos são **exemplos — confirmar após o diagnóstico**.

## Regras que não admitem exceção

1. **Nunca regenerar a `APP_KEY` em produção.** Ela cifra, no banco de controle, a senha do banco de cada instituição (`institutions.db_password`) e a chave de dados de cada instituição (`institutions.data_key`), além de sessões e jobs. Perder ou trocar a chave = perder o acesso a **todos** os bancos institucionais e aos dados cifrados de alunos. `php artisan key:generate` roda uma única vez, na criação do ambiente; nenhum script de deploy pode contê-lo. (O CI o executa apenas sobre um `.env` descartável.)
2. Nada roda como root: Composer, `artisan` e workers usam `deploy`/usuário web.
3. A aplicação nunca usa o root do MySQL/MariaDB.
4. Migrations destrutivas **nunca** são revertidas automaticamente.
5. Sem backup validado, não há migration em produção.
6. O `.env` vive em `shared/` e nunca entra em release, artefato ou repositório.

## Deploy sem terminal local

O computador do responsável não abre terminal. Fluxo proposto:

1. O **CI constrói a release** (código + `vendor/` sem dependências de desenvolvimento + `public/build`) e publica um artefato `.tar.gz` com soma SHA-256.
2. Um **script no servidor** (a escrever) baixa o artefato, confere a soma e executa as etapas 8 a 13.
3. O script é acionado por uma destas vias (decidir após o diagnóstico):
   - Terminal do WHM/cPanel no navegador (B) ou console web do Portal do Cliente HostGator;
   - cliente SSH em outro dispositivo;
   - job manual do GitHub Actions (`workflow_dispatch`) que conecta por SSH com chave exclusiva, limitada a esse comando (`command="..."` em `authorized_keys`). Exige guardar a chave como segredo do GitHub: depende de autorização do responsável.

Nunca fazer `git pull` nem `composer install` direto na pasta em produção.

## As 14 etapas do pipeline

| # | Etapa | Onde | Situação hoje |
|---|---|---|---|
| 1 | Verificação do código | CI | **Existe**: `vendor/bin/pint --test` (estilo). Não executado no GitHub ainda |
| 2 | Análise estática | CI | **Não existe**: não há PHPStan/Larastan no `composer.json` |
| 3 | Testes unitários | CI | **Parcial**: `php artisan test` roda, mas `tests/Unit` está vazio |
| 4 | Testes de integração | CI | **Existe**: testes de Feature contra MariaDB 10.11 real |
| 5 | Isolamento multi-tenant | CI | **Existe**: `tests/Feature/Tenancy/*` (isolamento HTTP, provisionamento, troca de contexto, jobs) |
| 6 | Auditoria de dependências | CI | **Parcial**: `composer audit` existe; não há auditoria de pacotes npm (nem `package-lock.json`) |
| 7 | Build do frontend | CI | **Não existe** no CI (`npm run build` está definido em `package.json`) |
| 8 | Preparação da release | CI + servidor | **Não existe** |
| 9 | Validação do backup | Servidor | **Não existe** (ver `BACKUP_RESTAURACAO.md`) |
| 10 | Verificação de migrations | Servidor | **Parcial**: o comando `tenants:migrate` existe; o procedimento não |
| 11 | Publicação controlada (symlink) | Servidor | **Não existe** |
| 12 | Reinício seguro dos workers | Servidor | **Não existe** (ver `PROCESSOS_E_FILAS.md`) |
| 13 | Smoke tests com `/healthz` | Servidor/externo | **Parcial**: o endpoint existe; o roteiro não |
| 14 | Monitoramento pós-deploy | Servidor/externo | **Não existe** (ver `MONITORAMENTO.md`) |

Etapas 1 a 7 bloqueiam o deploy: sem CI verde no commit exato, não há release.

## Roteiro no servidor (etapas 8 a 14)

```bash
# EXEMPLO do que o script fará — confirmar após o diagnóstico.
# BASE=/var/www/sociometria (A) ou /home/USUARIO/sociometria (B). Executar como "deploy" (A) ou a conta cPanel (B).
# ART = sociometria-<versão>.tar.gz gerado pelo CI, já baixado junto com o .sha256.
REL="$BASE/releases/$(date +%F_%H%M)_COMMIT"

# 8. Preparação
sha256sum -c "$ART.sha256"                       # recusa artefato adulterado ou incompleto
mkdir -p "$REL" && tar -xzf "$ART" -C "$REL"
ln -s "$BASE/shared/.env"    "$REL/.env"
rm -rf "$REL/storage" && ln -s "$BASE/shared/storage" "$REL/storage"

# 9. Backup: existe dump recente e íntegro do controle e de cada tenant? (script a escrever) — se não, PARAR

# 10. Migrations: ver o que está pendente antes de aplicar
cd "$REL" && php artisan migrate:status

# 11. Publicação: migrations e troca atômica do symlink
php artisan migrate --force                      # banco de controle
php artisan tenants:migrate --institution=PUBLIC_ID_PILOTO --force   # primeiro UMA instituição
php artisan tenants:migrate --force              # depois as demais, uma por vez
php artisan config:cache && php artisan route:cache
ln -sfn "$REL" "$BASE/current.new" && mv -T "$BASE/current.new" "$BASE/current"
# recarregar PHP-FPM (A: sudo systemctl reload php8.3-fpm | php-fpm · B: reinício do PHP-FPM pelo painel)

# 12. Workers
php artisan queue:restart

# 13. Smoke tests
curl -fsS https://DOMINIO/healthz                # esperado: {"status":"ok"}
curl -sI  https://DOMINIO/ | grep -iE 'strict-transport|x-correlation-id|cache-control'
```

Cuidados:

- Em A, `artisan` precisa gravar em `storage` e `bootstrap/cache`: executar como o usuário web (`sudo -u soc-web php artisan ...`) ou ajustar o grupo conforme `CONFIGURACAO_NGINX_OU_CPANEL.md`.
- `tenants:migrate` devolve código de saída diferente de zero se **qualquer** instituição falhar: o script deve parar antes de trocar o symlink e listar as que falharam (`falha <public_id>`). Ele inclui instituições ativas e suspensas.
- `/healthz` só prova que a aplicação responde e alcança o banco de controle. O smoke test completo inclui login com MFA de uma conta de teste e acesso a uma instituição de teste (nunca dados reais).
- Etapa 14: por 30 minutos, acompanhar erros 5xx, `failed_jobs`, `storage/logs` e eventos `denied`/`failure` em `audit_events`.
- Manter as 5 últimas releases; apagar as mais antigas só depois de os workers reiniciarem.
- Pendência a decidir: em desenvolvimento a conta de runtime do controle tem todos os privilégios no banco de controle, por isso consegue rodar `migrate`. Em produção, definir se as migrations do controle usam uma conta própria com DDL.

## Migrations

- Padrão **expandir → migrar → contrair**: uma release só adiciona (coluna/tabela nova, anulável); a remoção do que ficou obsoleto vai em release posterior. Assim a versão anterior do código continua funcionando com o esquema novo e o rollback por symlink é seguro.
- Migration destrutiva (`DROP`, mudança de tipo com perda, `DELETE` em massa) exige: dump validado feito imediatamente antes, janela combinada, aprovação do responsável e plano de restauração escrito. Não vai em deploy rotineiro.
- Tenants: sempre por `tenants:migrate`, um banco por vez. Nunca apontar `migrate` manualmente para um banco de tenant, nem rodar SQL em lote sobre vários bancos.
- Conferência: `institutions.schema_version` (nome da última migration aplicada) deve ficar igual para todas as instituições ativas.

## Rollback

```bash
# EXEMPLO — confirmar após o diagnóstico
ls -1 "$BASE/releases"                                   # escolher a release anterior
ln -sfn "$BASE/releases/ANTERIOR" "$BASE/current.new" && mv -T "$BASE/current.new" "$BASE/current"
cd "$BASE/current" && php artisan config:cache && php artisan route:cache
# recarregar PHP-FPM
php artisan queue:restart
curl -fsS https://DOMINIO/healthz
```

| Situação | Ação |
|---|---|
| Erro de código, migrations só aditivas | Rollback por symlink. O esquema novo permanece (compatível) |
| Migration aditiva com defeito | Corrigir com **nova** migration. Não usar `migrate:rollback` em produção |
| Migration destrutiva aplicada | **Sem reversão automática.** Restauração planejada por instituição, com aprovação (ver `BACKUP_RESTAURACAO.md`) |
| `tenants:migrate` falhou em parte das instituições | Não publicar. Corrigir a causa e repetir com `--institution=` nas que falharam (migrations já aplicadas não são refeitas) |
| `.env` alterado no deploy | Restaurar a cópia anterior de `shared/.env`. Jamais "resolver" gerando nova `APP_KEY` |

Registrar todo deploy e rollback: data, commit, quem executou, resultado dos
smoke tests e instituições migradas.
