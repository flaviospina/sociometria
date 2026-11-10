# Isolamento entre tenants (instituições)

Controles que impedem uma instituição de alcançar dados de outra, o que cada um prova e onde param. Este documento não declara conformidade com norma alguma; lista controles existentes e os testes que os exercitam.

## Estado

### Implementado e testado

| # | Controle | Código | Evidência |
|---|---|---|---|
| 1 | Banco e conta de banco exclusivos por instituição; conta só com DML no próprio banco | `TenantDatabaseAdmin::createRuntimeUser()` | `ProvisioningTest::test_tenant_credential_cannot_read_control_database_or_other_tenants`, `test_tenant_credential_cannot_open_another_tenants_database` |
| 2 | Verificação de isolamento antes de ativar a instituição | `InstitutionProvisioner::verifyIsolation()` | `ProvisioningTest::test_failed_provisioning_never_leaves_an_accessible_institution` |
| 3 | Tenant resolvido só por dados do servidor; nada de `tenant_id` vindo do cliente | `TenantResolver`, `InitializeTenant` | `HttpIsolationTest::test_tenant_identifier_sent_by_the_browser_is_ignored`, `test_forged_session_selection_without_membership_is_denied`, `test_user_cannot_select_context_of_institution_without_membership` |
| 4 | Revalidação de vínculo e estado a cada requisição (sem cache) | `TenantResolver::resolve()` | `test_revoked_membership_loses_access_immediately`, `test_suspending_one_institution_does_not_block_the_others` |
| 5 | Domínio verificado vincula o host a uma única instituição; host desconhecido é recusado | `TenantResolver::institutionForHost()` | `ResolverAndQuotaTest::test_verified_domain_binds_requests_to_its_institution_only` |
| 6 | Conexão `tenant` destruída ao fim de cada requisição/job | `TenantContext::leave()`, `TenantConnectionManager::disconnect()` | `test_tenant_context_and_connection_are_cleared_after_every_request`, `ContextSwitchingTest::test_tenant_connection_is_unusable_after_leaving_context`, `test_rapid_alternation_between_institutions_never_mixes_data` |
| 7 | Modelos de tenant sem contexto lançam exceção; objeto de A não grava em B | `TenantModel` | `test_tenant_models_are_unusable_without_context`, `test_object_loaded_in_one_institution_cannot_be_written_in_another` |
| 8 | Campos identificáveis de aluno cifrados com chave por instituição (AES-256-GCM) | `TenantEncrypter`, `Student` | `test_student_identifiers_are_encrypted_at_rest_with_per_institution_key` |
| 9 | Cache com namespace por instituição | `TenantCache` | `test_cache_is_namespaced_per_institution` |
| 10 | Jobs: payload cifrado, contexto revalidado, limpeza antes/depois | `TenantJob`, `InTenantContext`, `AppServiceProvider` | `TenantJobTest::test_workers_do_not_mix_institutional_contexts`, `test_job_for_suspended_or_unknown_institution_is_discarded`, `test_job_on_behalf_of_user_requires_still_active_membership`, `test_job_payload_is_encrypted` |
| 11 | Administrador da plataforma sem acesso padrão a dados institucionais; painel admin oculto (404) | `EnsurePlatformAdmin`, `Admin\InstitutionController` | `test_platform_admin_has_no_default_access_to_institutional_data`, `test_admin_panel_is_hidden_from_institution_users` |
| 12 | Identificadores públicos opacos (ULID); 404 para recurso de outra instituição | controladores `Tenant\*` | `test_changing_identifiers_in_url_never_exposes_another_institution`, `ResolverAndQuotaTest::test_full_multi_institution_scenario` |
| 13 | Auditoria de negações e acessos, sem dados de alunos | `AuditLogger` | `AuditTest`, `test_student_created_over_http_is_stored_encrypted_and_never_in_audit_log` |
| 14 | Sessão sem MFA ou não autenticada não chega ao tenant | `RequireMfa` | `test_unauthenticated_and_non_mfa_sessions_are_rejected` |

Os testes de tenancy criam bancos reais em MariaDB/MySQL (`tests/TenantTestCase.php`), com prefixos próprios `soc_tt_`/`soc_tu_`.

### Projetado, ainda não implementado

| Item | Etapa |
|---|---|
| Revogação de `UPDATE`/`DELETE` em `audit_events` no nível do banco | 9 |
| Procedimento de acesso emergencial de suporte (com justificativa, prazo e auditoria) | 9 |
| Separação do provisionador em processo/host distinto do web | 9–10 |
| KMS ou gerenciador externo de segredos para APP_KEY/DEKs | 9–10 |
| Reverificação periódica de privilégios das contas de tenant | 9 |
| Backups cifrados e segregados por tenant | 9 |
| Teste de intrusão independente | 9–10 |
| Offboarding com destruição de chave e banco | 9–10 |
| Verificação automatizada de propriedade de domínio | 3 (pendência) |

## Camadas de defesa

| Camada | Barreira | Natureza |
|---|---|---|
| Banco | Banco + conta por instituição; `GRANT` restrito; `SHOW DATABASES` só mostra o próprio banco | Física (imposta pelo servidor de banco) |
| Conexão | Conexão `tenant` nasce vazia, é configurada por `TenantContext` e destruída com `purge()` | Aplicação |
| Resolução | Usuário + sessão + host, sempre confrontados com `memberships` e `institutions.status` | Aplicação |
| ORM | `TenantModel` exige contexto e bloqueia gravação cruzada | Aplicação |
| Criptografia | DEK por instituição; texto cifrado de A não decifra em B | Aplicação (chaves no banco de controle) |
| Fila e cache | Payload cifrado + revalidação; prefixo `tenant:<public_id>:` | Aplicação |

O único controle que não depende de a aplicação estar correta é o primeiro. Os demais reduzem a chance de erro de programação virar vazamento.

## Limites e riscos residuais

1. **Comprometimento do servidor de aplicação quebra o isolamento.** A `APP_KEY` está no `.env` da mesma VPS e cifra `institutions.db_password` e `institutions.data_key`. Quem lê o `.env` e o banco de controle decifra as senhas de todos os tenants e todas as DEKs. O isolamento por banco protege contra falhas lógicas e contra o vazamento de **uma** credencial de tenant, não contra a tomada do servidor.
2. **Credencial do provisionador no processo web.** `TENANT_PROVISIONER_*` fica no mesmo `.env` e é carregada pelo processo que atende requisições. Ela tem `ALL ... WITH GRANT OPTION` sobre todos os bancos de tenant e `CREATE USER` global. Em MySQL/MariaDB, `CREATE USER` também permite `ALTER USER`/`DROP USER` de outras contas, o que em princípio inclui trocar a senha da conta de controle. Uma execução de código no web equivale a acesso a todos os tenants.
3. **Conta de runtime de controle com `ALL` no setup local.** `scripts/dev/setup_local_db.sh` concede `ALL PRIVILEGES` no banco de controle. Não há definição de privilégios mínimos para produção.
4. **`audit_events` é alterável no banco.** A imutabilidade é imposta pelo modelo `AuditEvent` (eventos `updating`/`deleting`) e a cadeia de hash **detecta** adulteração (`AuditLogger::verifyChain()`), mas não a impede. Quem tem a credencial de controle pode reescrever a cadeia inteira de forma consistente; não há âncora externa do último hash nem execução agendada de `verifyChain()`.
5. **Mesmo servidor de banco para todos.** Uma falha do MariaDB/MySQL, ou um superusuário do banco, alcança todos os tenants. `MAX_USER_CONNECTIONS` limita conexões, não CPU, E/S ou disco.
6. **Escolas da mesma instituição compartilham banco, credencial e DEK.** A separação entre escolas é lógica (`SchoolAccess`). Ver `docs/saas/ISOLAMENTO_ESCOLAS.md`.
7. **Consultas fora do Eloquent.** `DB::connection('tenant')` e SQL cru não passam pelas travas de `TenantModel`; dependem só da conexão ativa.
8. **Sessões, cache e fila no banco de controle.** O cache é separado por prefixo, não por armazenamento. Código que use `Cache::` diretamente, em vez de `TenantCache`, pode misturar dados.
9. **Rótulo do tenant no limitador** usa a instituição da sessão; sem seleção explícita, o teto agregado vira por usuário.
10. **Domínios**: `verified_at` é preenchido manualmente; um registro incorreto associa um host à instituição errada (o usuário ainda precisaria de vínculo ativo nela).
11. **Nome cifrado impede busca/ordenação em SQL**: é feita em memória (`StudentController::index()`), o que limita o volume por consulta.
12. **Somente `name`, `birth_date` e `registration_code` de `students` são cifrados.** Nomes de escolas e turmas, e toda a estrutura relacional, ficam em texto puro no banco do tenant.
13. **Suspensão não bloqueia a conta de banco** do tenant; o bloqueio é na aplicação.

## O que revisar ao adicionar código

- Todo modelo institucional estende `TenantModel`; todo job institucional estende `TenantJob`.
- Nunca aceitar identificador de instituição vindo da requisição, exceto como pedido em `POST /context`.
- Cache institucional somente via `TenantCache`.
- Nenhum dado de aluno em `audit_events.metadata`, logs ou banco de controle.
- Toda rota institucional sob o grupo `/app` (middleware `InitializeTenant`) e com checagem de `SchoolAccess`.
- Novo controle de isolamento entra neste documento apenas com o nome do teste que o comprova.
