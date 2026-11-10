# Plano técnico de implementação e estado atual

Atualizado ao fim da primeira entrega. Fonte de verdade sobre o que existe.

## Conflitos identificados e decisões

| Tema | Decisão | Motivo |
|---|---|---|
| Prompt Mestre | **Não está no repositório** (que estava vazio). Esta entrega seguiu apenas o Complemento | Requisitos pedagógicos (questionários, sociogramas, relatórios) dependem dele: é preciso adicioná-lo antes da Etapa 5 |
| Padrão habitual (PHP puro em hospedagem compartilhada) × Complemento (Laravel 13 em VPS) | Laravel 13 | Pedido explícito; provisionar bancos e contas por instituição exige VPS |
| MySQL 8 × MariaDB | Compatível com ambos (`DB_DRIVER`); testado em MariaDB 10.11 | A VPS ainda não foi diagnosticada |
| Redis | Não usado; sessão, cache e fila em banco | Menos um serviço a proteger no piloto; reavaliar com medições (Etapa 9) |
| Pacote de multi-tenancy de terceiros | Não usado; núcleo próprio e pequeno em `app/Tenancy` | Superfície auditável e testes negativos próprios |
| Interface | Somente endpoints JSON por enquanto | Prioridades 1–4 (proteção, isolamento, integridade, autenticação) antes de UX |

## Estado por etapa

| Etapa | Estado | Observações |
|---|---|---|
| 1 Diagnóstico e documentação | **Parcial** | Arquitetura, modelo de ameaças e matriz inicial escritos. Diagnóstico da VPS **pendente** (sem acesso; script pronto) |
| 2 Fundação segura | **Parcial** | Feito: login, MFA TOTP, rate limiting, RBAC por papel, auditoria encadeada, cabeçalhos. Feito também: `platform:create-admin`. Falta: códigos de recuperação de MFA, redefinição de senha, sessões simultâneas |
| 3 Multi-tenancy | **Núcleo concluído** | Provisionamento, credenciais separadas, TenantResolver, conexões dinâmicas, isolamento entre instituições e escolas, testes negativos. Falta: acesso emergencial de suporte, verificação automatizada de domínio |
| 4 Gestão escolar | **Iniciada** | Esquema completo; endpoints de escola, turma (listar) e aluno. Falta: CRUD completo, anos letivos, vínculos de equipe, importação |
| 5 Questionários | Não iniciada | Requer o Prompt Mestre |
| 6 Motor sociométrico | Não iniciada | |
| 7 Dashboards | Não iniciada | |
| 8 Relatórios e intervenções | Não iniciada | |
| 9 Segurança operacional | Não iniciada | Backups, monitoramento, testes de carga e de restauração |
| 10 Homologação e produção | Não iniciada | Nada foi implantado |

## Evidência de testes (ambiente local, MariaDB 10.11 real)

`php artisan test` → 52 testes, todos passando.

| Requisito do Complemento (§32) | Teste |
|---|---|
| Professor não acessa outra escola | `HttpIsolationTest::test_teacher_reaches_only_assigned_class`, `test_director_cannot_access_other_school_of_same_institution` |
| Administrador institucional não acessa outra instituição | `test_user_cannot_select_context_of_institution_without_membership`, `test_forged_session_selection_without_membership_is_denied` |
| Alterar identificadores na URL não expõe dados | `test_changing_identifiers_in_url_never_exposes_another_institution`, `test_tenant_identifier_sent_by_the_browser_is_ignored` |
| Workers não misturam contextos | `TenantJobTest::test_workers_do_not_mix_institutional_contexts` |
| Cache sem dados de outro tenant | `ContextSwitchingTest::test_cache_is_namespaced_per_institution` |
| Credenciais de banco segregadas | `ProvisioningTest::test_tenant_credential_cannot_read_control_database_or_other_tenants` |
| Suspensão não bloqueia as demais | `HttpIsolationTest::test_suspending_one_institution_does_not_block_the_others` |
| Painel SaaS não revela dados identificáveis | `test_platform_admin_has_no_default_access_to_institutional_data` |
| Alternância rápida de contexto | `ContextSwitchingTest::test_rapid_alternation_between_institutions_never_mixes_data` (150 trocas, mesmo processo) |
| Cenário 3 instituições × 2 escolas × 2 turmas × 30 alunos | `ResolverAndQuotaTest::test_full_multi_institution_scenario` |
| Relatórios, exportações, restauração | **Sem teste: módulos ainda não existem** |

Limites dessa evidência: a alternância de contexto foi testada em sequência num
único processo, não com processos concorrentes reais; não há testes de carga;
o workflow de CI foi escrito mas ainda não rodou no GitHub.

## Riscos restantes

1. `APP_KEY` na mesma VPS que os dados: protege contra vazamento de dump ou de
   backup do banco, não contra comprometimento do servidor.
2. Credencial do provisionador no mesmo `.env` do processo web.
3. Sem códigos de recuperação de MFA: perda do dispositivo exige intervenção manual.
4. Sem backups, monitoramento ou restauração testada — **bloqueante para dados reais**.
5. `audit_events` é imutável pela aplicação e a cadeia de hash detecta
   adulteração, mas `UPDATE/DELETE` ainda não foram revogados no banco.
6. Separação entre escolas da mesma instituição é lógica (mesmo banco).
7. Validação jurídica (LGPD, hipótese legal, avisos) cabe à instituição e não foi feita.

## Próxima etapa proposta

Concluir a Etapa 2 (recuperação de MFA, redefinição de senha, sessões simultâneas) e a Etapa 4 (gestão escolar completa com importação),
e executar o diagnóstico da VPS para fechar a Etapa 1.
