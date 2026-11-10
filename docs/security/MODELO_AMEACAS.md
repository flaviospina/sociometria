# Modelo de ameaças

Documento vivo. Descreve o que o código faz hoje, não o que se pretende fazer. Revisar a cada etapa do plano.

Etapas do plano: 1 Diagnóstico e documentação · 2 Fundação segura · 3 Multi-tenancy · 4 Gestão escolar · 5 Questionários · 6 Motor sociométrico · 7 Dashboards · 8 Relatórios e intervenções · 9 Segurança operacional · 10 Homologação e produção.

## 1. Estado

### Implementado e testado

| Controle | Código | Testes (em `tests/Feature`) |
|---|---|---|
| Um banco e uma credencial de banco por instituição (somente DML, só no próprio banco) | `app/Tenancy/Provisioning/TenantDatabaseAdmin.php`, `InstitutionProvisioner.php` (`verifyIsolation`) | `Tenancy/ProvisioningTest`: `test_tenant_credential_cannot_read_control_database_or_other_tenants`, `test_tenant_credential_cannot_open_another_tenants_database`, `test_failed_provisioning_never_leaves_an_accessible_institution` |
| Contexto institucional resolvido no servidor (usuário + sessão + host verificado), revalidado a cada requisição | `app/Tenancy/TenantResolver.php`, `app/Http/Middleware/InitializeTenant.php`, `app/Http/Controllers/ContextController.php` | `Tenancy/HttpIsolationTest`: `test_tenant_identifier_sent_by_the_browser_is_ignored`, `test_forged_session_selection_without_membership_is_denied`, `test_revoked_membership_loses_access_immediately`, `test_tenant_context_and_connection_are_cleared_after_every_request`; `Tenancy/ResolverAndQuotaTest::test_verified_domain_binds_requests_to_its_institution_only` |
| Travas no modelo: sem contexto não há consulta; objeto de A não é gravado em B | `app/Models/Tenant/TenantModel.php`, `app/Tenancy/TenantContext.php`, `TenantConnectionManager.php` | `Tenancy/ContextSwitchingTest`: `test_tenant_models_are_unusable_without_context`, `test_object_loaded_in_one_institution_cannot_be_written_in_another`, `test_rapid_alternation_between_institutions_never_mixes_data` |
| Escopo por escola e turma (`staff_scopes`), 404 para recurso fora do escopo | `app/Services/SchoolAccess.php`, `app/Http/Controllers/Tenant/*` | `HttpIsolationTest`: `test_director_cannot_access_other_school_of_same_institution`, `test_teacher_reaches_only_assigned_class`, `test_changing_identifiers_in_url_never_exposes_another_institution` |
| Campos identificáveis do aluno cifrados (AES-256-GCM) com chave por instituição; chave e senha do banco cifradas com `APP_KEY` | `app/Tenancy/TenantEncrypter.php`, `app/Casts/TenantEncrypted.php`, `app/Models/Tenant/Student.php`, `app/Models/Control/Institution.php` | `ContextSwitchingTest::test_student_identifiers_are_encrypted_at_rest_with_per_institution_key`, `ProvisioningTest::test_credentials_and_data_key_are_encrypted_at_rest_and_hidden`, `ResolverAndQuotaTest::test_student_created_over_http_is_stored_encrypted_and_never_in_audit_log` |
| Login em duas etapas (senha + TOTP), anti-replay, limite por conta, regeneração de sessão | `app/Http/Controllers/Auth/LoginController.php`, `app/Services/MfaService.php`, `app/Http/Middleware/RequireMfa.php`, `config/security.php` | `Auth/LoginTest`: `test_login_is_rate_limited_per_account`, `test_password_alone_does_not_authenticate_when_mfa_is_enabled`, `test_totp_code_cannot_be_replayed`, `test_session_id_is_regenerated_on_authentication`, `test_wrong_password_and_unknown_account_are_indistinguishable` |
| Painel da plataforma restrito e sem acesso a dados institucionais | `app/Http/Middleware/EnsurePlatformAdmin.php`, `app/Http/Controllers/Admin/InstitutionController.php` | `HttpIsolationTest`: `test_platform_admin_has_no_default_access_to_institutional_data`, `test_admin_panel_is_hidden_from_institution_users`; `ProvisioningTest::test_only_platform_admin_can_provision` |
| Auditoria encadeada por hash, sem dados de alunos nem segredos, IP truncado | `app/Services/AuditLogger.php`, `app/Models/Control/AuditEvent.php` | `AuditTest` (3 testes), `LoginTest::test_authentication_events_are_audited_without_secrets` |
| Filas e cache com contexto institucional | `app/Tenancy/Queue/TenantJob.php`, `InTenantContext.php`, `app/Tenancy/TenantCache.php`, `app/Providers/AppServiceProvider.php` | `Tenancy/TenantJobTest` (4 testes), `ContextSwitchingTest::test_cache_is_namespaced_per_institution` |
| Quotas do plano no backend | `app/Services/QuotaService.php` | `ResolverAndQuotaTest::test_plan_limits_are_enforced_by_backend_and_cannot_be_changed_by_request` |
| Cabeçalhos de segurança | `app/Http/Middleware/SecurityHeaders.php` | `LoginTest::test_responses_carry_security_headers_and_health_endpoint_is_minimal` (verifica só `nosniff`, `X-Frame-Options`, `Cache-Control` e `X-Correlation-Id`) |

### Projetado, ainda não implementado

| Item | Etapa |
|---|---|
| Interface web; gestão completa de turmas, escopos e vínculos (hoje não há rota para revogar vínculo nem para cadastrar `staff_scopes` ou domínios) | 4 |
| Questionários, respostas e credenciais temporárias de estudantes | 5 |
| Motor sociométrico | 6 |
| Dashboards | 7 |
| Relatórios/PDF, exportações, uploads | 8 |
| Backups e restauração, monitoramento e alertas, acesso emergencial de suporte, códigos de recuperação de MFA, redefinição de senha, controle de sessões simultâneas, passkeys, verificação agendada da cadeia de auditoria | 9 |
| Implantação em servidor (nenhum servidor configurado; a VPS HostGator não foi diagnosticada — `scripts/infra/diagnostico_vps.sh` existe, mas não foi executado) | 10 |

## 2. Ativos e fronteiras

- **Ativos**: dados identificáveis de alunos (nome, data de nascimento, código de matrícula); futuramente respostas sociométricas e relatórios; contas de profissionais; `APP_KEY`; credencial do provisionador; trilha de auditoria.
- **Fronteiras de confiança**: navegador ↔ aplicação; aplicação ↔ banco de controle; aplicação ↔ banco de cada instituição; processo web ↔ credencial de provisionamento; plataforma ↔ instituição.
- **Atores**: atacante externo; profissional de outra instituição; profissional da mesma instituição fora do seu escopo; administrador da plataforma; quem obtenha acesso ao servidor ou a um backup.

## 3. Ameaças

Legenda: P = probabilidade (B/M/A) · I = impacto (B/M/A/Crítico) · D = capacidade de detecção hoje (B/M/A).
Probabilidade e impacto de módulos inexistentes são estimativas para quando o módulo existir.

| # | Ameaça | P | I | D | Preventivas | Detectivas | Corretivas | Estado |
|---|---|---|---|---|---|---|---|---|
| 1 | Vazamento de respostas de alunos | M | Crítico | B | Projetadas: cifra com chave da instituição, acesso por escopo, agregação mínima | Projetadas: auditoria de cada leitura | Projetadas: suspensão, notificação | **Módulo ainda não existe; controles projetados para a Etapa 5** (cifra por instituição e escopo já existem para `students`) |
| 2 | Acesso indevido entre instituições | B | Crítico | M | Banco e credencial por instituição; resolver no servidor; travas do `TenantModel`; `verifyIsolation` no provisionamento | `tenant.resolve`/`tenant.select` com `denied` na auditoria | Suspender instituição; revogar vínculo | **Implementada + teste** (`ProvisioningTest`, `HttpIsolationTest`, `ContextSwitchingTest`) |
| 3 | Acesso indevido entre escolas da mesma instituição | M | A | B | `SchoolAccess` + `staff_scopes`; busca explícita com 404 | `students.listed` auditado; negações por escopo **não** são auditadas | Ajustar escopo; revogar vínculo | **Parcial**: implementada + teste, mas a separação é só lógica (mesmo banco e mesma chave) e cada novo controlador precisa chamar `SchoolAccess`. Gestão de escopos: Etapa 4 |
| 4 | Comprometimento de credenciais administrativas | M | Crítico | M | TOTP obrigatório; senha ≥ 12 com letras e números (no aceite do convite); `is_platform_admin` fora de atribuição em massa; troca de MFA bloqueada com sessão ativa | `auth.login`, `auth.mfa`, `admin.access` na auditoria | Desativar usuário (`users.status`), direto no banco | **Parcial**: MFA + teste. Sem verificação de senhas vazadas, sem códigos de recuperação, sem redefinição de senha, sem alerta (Etapa 9) |
| 5 | Roubo de sessões | M | A | B | Cookie `HttpOnly`, `SameSite=lax`; regeneração no login, no MFA e na troca de contexto; `.env.example` define 30 min, expiração ao fechar e sessão cifrada | Nenhuma específica | Logout invalida a sessão; apagar linhas de `sessions` | **Parcial**: regeneração testada. Padrões de `config/session.php` são mais frouxos que o `.env.example` (120 min, sem cifra); cookie `Secure` depende de variável; sem vínculo a dispositivo nem lista/revogação de sessões (Etapa 9) |
| 6 | Vazamento de relatórios PDF | M | Crítico | B | Projetadas: geração sob demanda, armazenamento fora da raiz pública, link autenticado e expirável | Projetadas: auditoria de geração e download | Projetadas: revogar arquivo | **Módulo ainda não existe; controles projetados para a Etapa 8** |
| 7 | Força bruta | A | A | M | 5 tentativas/conta e 30/IP em 15 min; `throttle:20,1` nas rotas; 5 tentativas no desafio MFA; resposta e custo iguais para conta inexistente | `auth.login` com `failure`/`denied` | Bloqueio temporário automático | **Implementada + teste** (limite por conta). Limite por IP e do MFA sem teste dedicado; sem alerta (Etapa 9) |
| 8 | SQL Injection | B | Crítico | B | Eloquent/consultas parametrizadas; nenhum SQL com entrada do usuário; identificadores de DDL validados por padrão estrito e senha via `PDO::quote`; credencial do tenant sem DDL | Nenhuma | Corrigir e rotacionar credenciais | **Parcial**: implementada por construção; sem teste dedicado de injeção nem análise estática (Etapa 9) |
| 9 | XSS | B | A | B | Respostas apenas JSON; CSP `default-src 'self'`; `nosniff` | Nenhuma | Corrigir saída | **Parcial**: não há interface para validar escape nem a CSP (Etapas 4 e 7) |
| 10 | CSRF | B | A | B | Proteção padrão do Laravel no grupo `web` (`PreventRequestForgery`); `SameSite=lax` | Nenhuma | — | **Parcial**: ativa por padrão, **sem teste dedicado** (o framework a desativa em testes). Validar com a interface (Etapa 4) |
| 11 | IDOR / falhas de autorização | M | Crítico | M | IDs públicos ULID; ids internos ocultos; busca explícita + `SchoolAccess`; nega por padrão sem vínculo | Auditoria de negações de contexto | Corrigir regra | **Implementada + teste** para as rotas existentes (`test_changing_identifiers_in_url_never_exposes_another_institution`). Sem Policies centralizadas; cada rota nova exige teste próprio |
| 12 | Abuso de credenciais temporárias de estudantes | M | A | B | Projetadas: código de uso único, curta validade, vinculado a uma aplicação, limite de tentativas | Projetadas | Projetadas: invalidar lote | **Módulo ainda não existe; controles projetados para a Etapa 5** |
| 13 | Comprometimento do servidor | B | Crítico | B | Credencial por tenant limita o alcance de uma credencial vazada | Cadeia de hash da auditoria | Rotação de chaves e senhas (procedimento não escrito) | **Pendente** (Etapas 9 e 10). Risco residual: `APP_KEY` e credencial do provisionador ficam na mesma máquina e no mesmo `.env` do processo web; quem ler o `.env` e o banco de controle alcança todos os tenants |
| 14 | Comprometimento de dependências | M | A | M | `composer.lock` versionado; poucas dependências diretas | `composer audit` no CI (`.github/workflows/ci.yml`) | Atualizar e reimplantar | **Parcial**: sem auditoria de pacotes npm, sem atualização automatizada (Etapa 9) |
| 15 | Vazamento de backups | M | Crítico | B | Projetadas: backup cifrado por instituição, chave fora do servidor | Projetadas | Projetadas | **Módulo ainda não existe; controles projetados para a Etapa 9**. Hoje não há backup algum |
| 16 | Funcionários com privilégios excessivos | M | A | M | Admin da plataforma sem vínculo não lê dados institucionais; papéis (`app/Enums/Role.php`); criação de escola só para admin institucional | Auditoria de ações administrativas | Revogar vínculo; desativar usuário | **Parcial**: implementada + teste na aplicação. Quem opera o servidor ou o banco não é contido; sem acesso emergencial controlado (Etapa 9) |
| 17 | Exportações não autorizadas | M | A | B | Projetadas: permissão específica, limite de volume, auditoria | Projetadas | Projetadas | **Módulo ainda não existe; controles projetados para a Etapa 8**. Hoje a única saída em massa é a listagem de alunos por turma (auditada) |
| 18 | Negação de serviço | M | M | B | `throttle:tenant` (120/min por usuário, 1500/min por instituição); `MAX_USER_CONNECTIONS` por credencial; limites de tamanho na validação; jobs com `tries`/`timeout` | Nenhuma | — | **Parcial**: implementada, sem teste dedicado; sem proteção de rede nem monitoramento (Etapas 9 e 10) |
| 19 | Falhas de isolamento em cache e filas | B | A | M | `TenantCache` com prefixo do contexto; payload de job cifrado; contexto revalidado na execução e limpo antes/depois de todo job | `job.context` com `denied` | Limpar cache; descartar job | **Implementada + teste** (`TenantJobTest`, `test_cache_is_namespaced_per_institution`). O uso de `TenantCache` é convenção: nada impede `Cache::` direto; armazenamento de cache e fila é compartilhado |
| 20 | Restauração de dados de um tenant sobre outro | B | Crítico | B | Projetadas: backup identificado pelo `public_id`, verificação antes de restaurar, restauração em banco novo | Projetadas | Projetadas | **Módulo ainda não existe; controles projetados para a Etapa 9**. Atenuante atual: dados cifrados com a chave de A são ilegíveis no contexto de B |

## 4. Riscos residuais aceitos nesta fase

1. **`APP_KEY` na mesma VPS que os dados.** Ela cifra as senhas dos bancos dos tenants e as chaves de dados. A cifra de campos protege contra vazamento isolado do banco ou de um dump, não contra comprometimento do servidor.
2. **Credencial do provisionador no `.env` do processo web.** Ela cria bancos e contas e tem privilégios sobre todos os bancos de tenants. Um RCE na aplicação web a alcança. Separar em processo/usuário distinto: Etapa 9.
3. **`audit_events` sem revogação de `UPDATE`/`DELETE` no banco.** A imutabilidade é imposta só pelo modelo Eloquent; a cadeia de hash detecta alteração e remoção no meio (`AuditTest::test_hash_chain_detects_tampering_and_deletion`), mas não a remoção dos eventos mais recentes, nem uma reescrita completa da cadeia por quem tenha acesso de escrita. Não há âncora externa nem execução agendada de `verifyChain` (Etapa 9).
4. **Separação entre escolas é lógica.** Depende de cada controlador consultar `SchoolAccess`.
5. **CSP e cabeçalhos definidos sem interface para validá-los.** HSTS só é enviado em requisições HTTPS.
6. **CSRF sem teste dedicado.**
7. **Sessões**: a tabela `sessions` guarda IP completo e user agent dos profissionais; os valores seguros de sessão vêm do `.env`, não dos padrões do código.
8. **Sem recuperação de conta**: perda do TOTP ou da senha exige intervenção manual no banco, sem procedimento definido.
