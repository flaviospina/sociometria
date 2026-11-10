# Provisionamento de instituições

Como uma instituição (tenant) ganha banco, credencial, chave de dados e esquema. Código: `app/Tenancy/Provisioning/`.

## Estado

### Implementado e testado

| Item | Código | Evidência (`tests/Feature/Tenancy/ProvisioningTest.php`, salvo indicação) |
|---|---|---|
| Provisionamento completo com identificadores opacos | `InstitutionProvisioner::provision()` | `test_provisioning_creates_isolated_database_with_opaque_identifiers` |
| Conta do tenant restrita ao próprio banco, sem DDL | `TenantDatabaseAdmin::createRuntimeUser()` | `test_tenant_credential_cannot_read_control_database_or_other_tenants`, `test_tenant_credential_cannot_open_another_tenants_database` |
| Senha do banco e DEK cifradas em repouso e ocultas em JSON | `app/Models/Control/Institution.php` | `test_credentials_and_data_key_are_encrypted_at_rest_and_hidden` |
| Somente administrador da plataforma provisiona | `InstitutionProvisioner::authorize()`, `EnsurePlatformAdmin` | `test_only_platform_admin_can_provision`, `HttpIsolationTest::test_admin_panel_is_hidden_from_institution_users` |
| Falha nunca deixa instituição acessível | bloco `catch` de `InstitutionProvisioner::run()` | `test_failed_provisioning_never_leaves_an_accessible_institution` |
| Retomada de provisionamento falho | `InstitutionProvisioner::resume()` | `test_failed_provisioning_can_be_resumed` |
| Provisionamento via API + convite do primeiro administrador | `Admin\InstitutionController::store()` | `ResolverAndQuotaTest::test_onboarding_through_admin_api_and_invitation` |

Implementado **sem teste automatizado**: `php artisan tenants:migrate`.

### Projetado, ainda não implementado

| Item | Etapa |
|---|---|
| Endpoint ou comando para `resume()` (hoje só é chamável por código) | 3 (pendência) |
| Limpeza de bancos de provisionamentos `failed` (`TenantDatabaseAdmin::dropDatabase()` existe, mas nada o chama) | 9 |
| Provisionamento assíncrono em fila (hoje é síncrono dentro da requisição HTTP) | 9 |
| Backup inicial e inclusão do tenant em rotina de backup | 9 |
| Cadastro/verificação de domínio próprio no provisionamento | 3 (pendência) |
| Seeder ou tela de planos (`plans` é preenchida manualmente) | 4 |

## Pré-requisitos no servidor de banco

Duas contas, nenhuma delas `root`:

| Conta | Privilégios esperados |
|---|---|
| Runtime de controle (`DB_USERNAME`) | Acesso ao banco de controle |
| Provisionador (`TENANT_PROVISIONER_USERNAME`) | `CREATE USER` global; `ALL ... WITH GRANT OPTION` sobre o padrão de nomes dos tenants (`soc\_t\_%`) |

Em desenvolvimento, `scripts/dev/setup_local_db.sh` cria as duas contas com senhas `dev-only-*`, **exclusivas de desenvolvimento/teste** (o próprio script proíbe uso em produção). Não existe script equivalente para produção (Etapa 10).

## Entrada

`POST /admin/institutions` (rota protegida por `auth`, `RequireMfa`, `EnsurePlatformAdmin`):

| Campo | Regra |
|---|---|
| `name` | obrigatório, até 160 caracteres |
| `admin_email` | obrigatório, e-mail |
| `plan_code` | opcional; precisa existir em `plans.code` |

Resposta 201 com `id` (ULID público), `name`, `status`, `subscription_status`, `plan`, `schema_version`, `provisioned_at`. Nome do banco, usuário, senha e DEK nunca são retornados.

## Etapas (`InstitutionProvisioner`)

1. **Autorização**: `is_platform_admin` e usuário ativo; caso contrário audita `institution.provisioned`/`denied` e lança `AuthorizationException`.
2. **Registro em `institutions`** com `status = provisioning`:
   - `public_id`: ULID;
   - `db_name` / `db_username`: prefixo configurado (`TENANT_DB_PREFIX`, `TENANT_DB_USER_PREFIX`) + 16 hex aleatórios; nunca derivam do nome do cliente;
   - `db_password`: 40 caracteres aleatórios (`Str::password`, sem símbolos), cifrada pelo cast `encrypted`;
   - `data_key`: DEK de 256 bits (`TenantEncrypter::generateKey()`), cifrada pelo cast `encrypted`.
3. **`createDatabase`**: `CREATE DATABASE IF NOT EXISTS` em `utf8mb4`.
4. **`createRuntimeUser`**: `CREATE USER IF NOT EXISTS`, `ALTER USER ... WITH MAX_USER_CONNECTIONS` (padrão 10) e `GRANT SELECT, INSERT, UPDATE, DELETE` somente no banco do tenant (o `_` do nome é escapado no padrão do `GRANT`).
5. **`TenantMigrator::migrate`**: executa `database/migrations/tenant` pela conexão `tenant_migrator` e devolve o nome da última migration como `schema_version`.
6. **`verifyIsolation`**: conecta com a credencial do próprio tenant e exige que ela (a) leia `schools`; (b) veja apenas o próprio banco e `information_schema` em `SHOW DATABASES`; (c) não leia `institutions` do banco de controle; (d) não consiga `CREATE TABLE`.
7. **Ativação**: `status = active`, `provisioned_at`, `schema_version`.
8. **Convite** do primeiro `institution_admin` (`InvitationService::invite`) e auditoria `institution.provisioned`/`success`.

Antes do passo 7 a instituição não é utilizável: `TenantResolver` recusa qualquer estado diferente de `active`.

## Falha e retomada

Em qualquer exceção: o contexto é limpo, o estado vira `failed`, a conta de banco do tenant é removida (melhor esforço) e é registrado `institution.provisioned`/`failure` apenas com a classe do erro. O banco criado é preservado para análise e **não é removido automaticamente**.

`resume()` aceita instituições em `provisioning` ou `failed` e reexecuta os passos 3–8; todas as operações de banco são idempotentes (`IF NOT EXISTS`, `ALTER USER`, migrations já aplicadas são ignoradas).

Observação: o convite é enviado depois da ativação. Se o envio de e-mail lançar exceção, a instituição volta para `failed` e a conta de banco é removida; a retomada recria a conta e gera novo convite.

## Proteções contra injeção em DDL

Nomes de banco e de usuário não podem ser parametrizados. `TenantDatabaseAdmin::assertIdentifier()` exige prefixo `^[a-z_]{3,16}$` e nome `^<prefixo>[0-9a-f]{16,24}$` (até 64/32 caracteres) antes de interpolar; a senha passa por `PDO::quote()`; o host da conta é validado por expressão regular.

## Migrations em tenants existentes

```
php artisan tenants:migrate                       # todas as instituições active/suspended
php artisan tenants:migrate --institution=<ULID>  # apenas uma
```

Em `production` exige `--force`. O comando atualiza `schema_version` e audita `tenant.migrated` (`success`/`failure`) por instituição. Ele **não** faz nem valida backup: a mensagem pede validação prévia, mas a rotina de backup é da Etapa 9.

## Limites conhecidos

- A credencial do provisionador fica no mesmo `.env` do processo web (ver `docs/security/GESTAO_SEGREDOS.md`).
- `verifyIsolation` roda apenas no provisionamento/retomada; não há reverificação periódica dos privilégios.
- Não há reconciliação entre `institutions` e os bancos/contas realmente existentes no servidor.
