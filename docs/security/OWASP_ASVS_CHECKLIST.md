# Matriz OWASP ASVS (inicial)

Referência: OWASP ASVS 5.0, nível 2.

> **Sobre os identificadores.** Esta matriz identifica os controles **por capítulo** da ASVS 5.0 (ex.: "V6 Autenticação") seguido de um sufixo local (`-a`, `-b`…). Os sufixos **não** são números de requisito da ASVS. O mapeamento item a item, com a numeração oficial, será feito na **Etapa 9 (Segurança operacional)**. Os nomes de capítulo devem ser conferidos com o texto oficial nessa ocasião.
>
> Nenhuma linha declara conformidade sem teste automatizado. "Atendido com teste" significa: existe código e existe teste que falha se o controle for removido — não é uma avaliação formal.

## 1. Estado

### Implementado e testado

Controles com estado **Atendido com teste** na matriz abaixo. Todos os testes ficam em `tests/Feature` e rodam contra MariaDB real no CI (`.github/workflows/ci.yml`), que também executa `composer audit` e `pint --test`.

### Projetado, ainda não implementado

| Item | Etapa |
|---|---|
| Interface web (validação de CSP, escape de saída, CSRF ponta a ponta) | 4 e 7 |
| Questionários, respostas, credenciais temporárias de estudantes | 5 |
| Relatórios/PDF, exportações, uploads | 8 |
| Redefinição de senha, códigos de recuperação de MFA, sessões simultâneas, passkeys, backups, monitoramento, acesso emergencial, mapeamento ASVS item a item | 9 |
| TLS, endurecimento do servidor, implantação | 10 |

## 2. Matriz

Estados: **Atendido com teste** · **Parcial** · **Pendente** · **Não aplicável ainda**.
Abreviações de teste: `Login` = `Auth/LoginTest`; `Http` = `Tenancy/HttpIsolationTest`; `Prov` = `Tenancy/ProvisioningTest`; `Ctx` = `Tenancy/ContextSwitchingTest`; `Job` = `Tenancy/TenantJobTest`; `Quota` = `Tenancy/ResolverAndQuotaTest`; `Audit` = `AuditTest`.

| Id | Descrição | Risco mitigado | Módulo | Implementação | Teste | Evidência | Estado |
|---|---|---|---|---|---|---|---|
| V1 Codificação e sanitização-a | Consultas parametrizadas; sem SQL montado com entrada do usuário | SQL Injection | Todos | Eloquent/Query Builder em todo o código | — | Revisão de código | Parcial |
| V1-b | Identificadores de DDL validados antes de interpolar | Injeção em DDL | Provisionamento | `TenantDatabaseAdmin::assertIdentifier`, `PDO::quote` para senha | `Prov::test_provisioning_creates_isolated_database_with_opaque_identifiers` (formato; não testa entrada maliciosa) | Código | Parcial |
| V1-c | Codificação de saída em HTML | XSS | Interface | Só JSON hoje | — | — | Não aplicável ainda (Etapa 4) |
| V2 Validação e lógica de negócio-a | Validação no servidor com limites de tamanho e formato | Entrada malformada | Controladores | `$request->validate()` em todos os controladores | `Quota::test_onboarding_through_admin_api_and_invitation` (senha curta e token inválido → 422) | Código | Parcial |
| V2-b | Limites de negócio aplicados no backend | Burla de plano | Quotas | `QuotaService`, `Institution::limit` | `Quota::test_plan_limits_are_enforced_by_backend_and_cannot_be_changed_by_request` | Teste | Atendido com teste |
| V2-c | Atribuição em massa bloqueada para campos privilegiados | Escalada de privilégio | Modelos | `$fillable` restrito em `User`; `$guarded = ['*']` nos modelos de controle | `Login::test_privileged_attributes_are_not_mass_assignable_and_secrets_are_hidden` | Teste | Atendido com teste |
| V3 Segurança de frontend web-a | `X-Content-Type-Options`, `X-Frame-Options`, `Cache-Control: no-store` | Clickjacking, cache de dados sensíveis | Global | `SecurityHeaders` | `Login::test_responses_carry_security_headers_and_health_endpoint_is_minimal` | Teste | Atendido com teste |
| V3-b | CSP, `Referrer-Policy`, `Permissions-Policy` | XSS, vazamento por referenciador | Global | `SecurityHeaders` | — (não verificados pelo teste; sem interface) | Código | Parcial |
| V3-c | HSTS | Rebaixamento para HTTP | Global | Enviado só se a requisição for HTTPS | — | Código | Parcial (Etapa 10) |
| V3-d | Proteção contra CSRF | Ações forjadas | Grupo `web` | Padrão do Laravel (`PreventRequestForgery`); `SameSite=lax` | — (desativada pelo framework em testes) | Configuração padrão | Parcial |
| V4 API e serviços web-a | Erros sem detalhes internos; resposta sempre JSON | Vazamento de informação | Global | `bootstrap/app.php` (`shouldRenderJsonWhen`); `HealthController` | `Login::…health_endpoint_is_minimal` | Teste | Parcial (`APP_DEBUG` precisa ser `false` em produção — Etapa 10) |
| V5 Tratamento de arquivos-a | Upload, armazenamento e download seguros | Arquivo malicioso, vazamento de PDF | Relatórios | Inexistente | — | — | Não aplicável ainda (Etapa 8) |
| V6 Autenticação-a | Política de senha (≥ 12, letras e números, máx. 1024) | Senha fraca | Convites | `InvitationController`, `config/security.php` | `Quota::test_onboarding_through_admin_api_and_invitation` | Teste (parcial) | Parcial — sem verificação de senhas vazadas |
| V6-b | Armazenamento de senha com hash adaptativo | Vazamento de senhas | `User` | Cast `hashed` (bcrypt) | — | Código | Parcial |
| V6-c | Resposta indistinguível para conta inexistente | Enumeração de contas | Login | `LoginController` (hash fictício) | `Login::test_wrong_password_and_unknown_account_are_indistinguishable` | Teste | Atendido com teste |
| V6-d | Limite de tentativas por conta | Força bruta | Login | `RateLimiter` (5 em 15 min) | `Login::test_login_is_rate_limited_per_account` | Teste | Atendido com teste |
| V6-e | Limite por IP e no desafio MFA | Força bruta distribuída | Login | `RateLimiter` (30/IP; 5 no MFA), `throttle:20,1` | — | Código | Parcial |
| V6-f | Segundo fator obrigatório para profissionais | Credencial comprometida | Login/MFA | `RequireMfa`, `MfaService` | `Login::test_password_alone_does_not_authenticate_when_mfa_is_enabled`, `test_user_without_mfa_can_only_enroll_until_second_factor_is_confirmed`, `test_mfa_challenge_cannot_be_used_without_password_step`; `Http::test_unauthenticated_and_non_mfa_sessions_are_rejected` | Teste | Atendido com teste |
| V6-g | Código TOTP de uso único | Replay | MFA | `mfa_last_timestep`, `verifyKeyNewer` | `Login::test_totp_code_cannot_be_replayed` | Teste | Atendido com teste |
| V6-h | Conta desativada não autentica | Acesso após desligamento | Login | `User::isActive` | `Login::test_disabled_user_cannot_log_in` | Teste | Atendido com teste |
| V6-i | Recuperação de conta (senha e MFA) | Bloqueio ou recuperação insegura | Autenticação | Inexistente | — | — | Pendente (Etapa 9) |
| V6-j | Convite com token aleatório, uso único, expiração, só o hash gravado | Sequestro de convite | Convites | `InvitationService` | `Quota::test_onboarding_through_admin_api_and_invitation` | Teste | Atendido com teste |
| V6-k | Autenticação de estudantes (credencial temporária) | Abuso de código de acesso | Questionários | Inexistente | — | — | Não aplicável ainda (Etapa 5) |
| V7 Gerenciamento de sessão-a | Novo identificador de sessão ao autenticar | Fixação de sessão | Login | `session()->regenerate()` | `Login::test_session_id_is_regenerated_on_authentication` | Teste | Atendido com teste |
| V7-b | Atributos do cookie (`HttpOnly`, `SameSite`, `Secure`) | Roubo de sessão | Sessão | `config/session.php`; `Secure` depende de `SESSION_SECURE_COOKIE` | — | Configuração | Parcial (Etapa 10) |
| V7-c | Expiração por inatividade e logout que invalida | Sessão esquecida | Sessão | `.env.example`: 30 min; padrão do código: 120 min; `logout` invalida | — | Configuração | Parcial |
| V7-d | Listar e encerrar sessões; limite de simultâneas | Sessão roubada persistente | Sessão | Inexistente | — | — | Pendente (Etapa 9) |
| V8 Autorização-a | Isolamento entre instituições imposto no servidor e no banco | Acesso entre tenants | Tenancy | `TenantResolver`, `TenantContext`, credencial por tenant | `Prov::test_tenant_credential_cannot_read_control_database_or_other_tenants`; `Http::test_tenant_identifier_sent_by_the_browser_is_ignored`, `test_forged_session_selection_without_membership_is_denied`, `test_user_cannot_select_context_of_institution_without_membership`; `Ctx::test_object_loaded_in_one_institution_cannot_be_written_in_another` | Teste | Atendido com teste |
| V8-b | Autorização por escola e turma | Acesso entre escolas | `SchoolAccess` | `staff_scopes` + verificação por controlador | `Http::test_director_cannot_access_other_school_of_same_institution`, `test_teacher_reaches_only_assigned_class` | Teste | Atendido com teste (separação lógica; sem Policies centralizadas) |
| V8-c | Referência direta a objeto não expõe recurso alheio | IDOR | Rotas `/app` | ULID público; 404 fora do escopo | `Http::test_changing_identifiers_in_url_never_exposes_another_institution` | Teste | Atendido com teste |
| V8-d | Revogação com efeito imediato | Acesso residual | Tenancy | Vínculo e estado revalidados a cada requisição | `Http::test_revoked_membership_loses_access_immediately`, `test_suspending_one_institution_does_not_block_the_others` | Teste | Atendido com teste (não há rota para revogar vínculo — Etapa 4) |
| V8-e | Funções administrativas restritas | Escalada de privilégio | Painel `/admin` | `EnsurePlatformAdmin`; `InstitutionProvisioner::authorize` | `Http::test_admin_panel_is_hidden_from_institution_users`, `test_platform_admin_has_no_default_access_to_institutional_data`; `Prov::test_only_platform_admin_can_provision` | Teste | Atendido com teste |
| V8-f | Contexto de autorização em jobs | Execução com vínculo revogado | Filas | `InTenantContext` | `Job::test_job_on_behalf_of_user_requires_still_active_membership`, `test_job_for_suspended_or_unknown_institution_is_discarded`, `test_workers_do_not_mix_institutional_contexts` | Teste | Atendido com teste |
| V11 Criptografia-a | Dados identificáveis de alunos cifrados com chave por instituição | Vazamento do banco | `Student` | `TenantEncrypter` (AES-256-GCM) | `Ctx::test_student_identifiers_are_encrypted_at_rest_with_per_institution_key`; `Quota::test_student_created_over_http_is_stored_encrypted_and_never_in_audit_log` | Teste | Atendido com teste |
| V11-b | Segredos cifrados em repouso e ocultos na serialização | Vazamento de credenciais | Controle | Casts `encrypted`; `$hidden` | `Prov::test_credentials_and_data_key_are_encrypted_at_rest_and_hidden`; `Job::test_job_payload_is_encrypted` | Teste | Atendido com teste |
| V11-c | Gestão do ciclo de vida de chaves (guarda separada, rotação) | Comprometimento de chave mestra | Infra | `APP_KEY` em `.env` na mesma máquina; sem rotação | — | — | Pendente (Etapa 9) |
| V12 Comunicação segura-a | TLS em todo o tráfego, inclusive banco | Interceptação | Infra | Opção `MYSQL_ATTR_SSL_CA` existe; nenhum servidor configurado | — | — | Pendente (Etapa 10) |
| V13 Configuração-a | Segredos fora do código; privilégio mínimo de banco | Vazamento de segredo; alcance excessivo | Infra | `.env`; credencial de tenant só com DML | `Prov` (isolamento de credencial) | Teste | Parcial — credencial do provisionador no mesmo `.env` do processo web |
| V13-b | Dependências sem vulnerabilidades conhecidas | Cadeia de suprimentos | CI | `composer audit` | — (verificação de CI) | `ci.yml` | Parcial — sem auditoria de npm |
| V14 Proteção de dados-a | Dados sensíveis fora de logs, auditoria e cache do navegador | Vazamento indireto | Auditoria | `AuditLogger` (só metadados técnicos); `no-store` | `Login::test_authentication_events_are_audited_without_secrets`; `Quota::…never_in_audit_log` | Teste | Atendido com teste |
| V14-b | Retenção e exclusão de dados | Guarda além do necessário | Privacidade | Inexistente | — | — | Pendente (Etapas 4–9) |
| V16 Logs e tratamento de erros-a | Eventos de segurança registrados com correlação | Falta de rastreabilidade | Auditoria | `AuditLogger`, `AssignCorrelationId` | `Login::test_authentication_events_are_audited_without_secrets` | Teste | Atendido com teste |
| V16-b | Proteção dos registros contra adulteração | Encobrimento | Auditoria | Cadeia de hash; bloqueio no modelo | `Audit::test_hash_chain_detects_tampering_and_deletion`, `test_audit_events_are_immutable_through_the_application` | Teste | Parcial — sem restrição no banco, sem âncora externa, sem verificação agendada |
| V16-c | Minimização da origem (IP truncado) | Excesso de dado pessoal em log | Auditoria | `AuditLogger::minimizeIp` | `Audit::test_origin_address_is_minimized` | Teste | Atendido com teste (a tabela `sessions` ainda guarda IP completo) |
| V16-d | Monitoramento e alertas | Incidente não percebido | Operação | Inexistente | — | — | Pendente (Etapa 9) |

Capítulos sem linha nesta matriz (por exemplo, tokens autocontidos, OAuth/OIDC e WebRTC) não se aplicam: o sistema não usa essas tecnologias. Confirmar na Etapa 9.

## 3. Relação com o OWASP Top 10:2025

A lista exata e a ordem das categorias do Top 10:2025 **devem ser conferidas na fonte oficial** (owasp.org) antes de qualquer declaração formal; aqui a relação é feita por tema, sem numeração.

| Tema | Situação |
|---|---|
| Controle de acesso quebrado | Principal foco do que está implementado (V8) |
| Configuração insegura | Parcial; depende da Etapa 10 |
| Cadeia de suprimentos de software | Parcial (`composer audit`) |
| Falhas criptográficas | Parcial; gestão de chaves pendente |
| Injeção | Parcial; sem teste dedicado |
| Falhas de autenticação | Atendido em grande parte; recuperação pendente |
| Falhas de registro e alerta | Registro existe; alerta pendente |

## 4. Relação com os Cheat Sheets da OWASP

| Cheat Sheet | Aplicado | Falta |
|---|---|---|
| Multi-Tenant Security | Banco e credencial por tenant; contexto derivado no servidor; limpeza de contexto em requisições e jobs; cache com namespace; quotas e limite de consumo por tenant; chave de dados por tenant | Backups por tenant; uso obrigatório (não convencional) de `TenantCache`; separação da credencial de provisionamento |
| Authorization | Negar por padrão; verificação em cada requisição; 404 para não revelar existência; testes de autorização por papel | Policies centralizadas; auditoria das negações por escopo de escola |
| Session Management | Regeneração de id; `HttpOnly`; `SameSite`; expiração; logout que invalida | `Secure` forçado; padrões seguros no código; gestão de sessões ativas |
| Logging | Eventos de autenticação, autorização e administração; sem segredos nem dados de alunos; correlação; detecção de adulteração | Armazenamento protegido fora do servidor; alertas; retenção definida |
