# Onboarding e offboarding de instituições

Entrada de uma instituição na plataforma e o que existe (pouco) sobre a saída. **O offboarding não está implementado**: só existem os valores `offboarding` e `closed` no enum `InstitutionStatus`.

## Estado

### Implementado e testado

| Item | Código | Evidência |
|---|---|---|
| Criação da instituição + convite do primeiro administrador | `Admin\InstitutionController::store()`, `InstitutionProvisioner`, `InvitationService::invite()` | `ResolverAndQuotaTest::test_onboarding_through_admin_api_and_invitation` |
| Aceite de convite: token de uso único, expiração, política de senha | `InvitationService::accept()`, `InvitationController` | mesmo teste (senha curta, token inválido e reuso retornam 422) |
| MFA obrigatório antes de qualquer recurso | `RequireMfa`, `MfaController`, `MfaService` | `LoginTest::test_user_without_mfa_can_only_enroll_until_second_factor_is_confirmed`, `test_totp_code_cannot_be_replayed` |
| Seleção explícita de contexto para quem tem mais de um vínculo | `ContextController` | `HttpIsolationTest::test_professional_with_two_institutions_must_select_context_explicitly`, `test_user_cannot_select_context_of_institution_without_membership` |
| Suspensão e reativação | `Admin\InstitutionController::suspend()/reactivate()` | `HttpIsolationTest::test_suspending_one_institution_does_not_block_the_others`, `TenantJobTest::test_job_for_suspended_or_unknown_institution_is_discarded` |
| Revogação de vínculo com efeito imediato | `TenantResolver` (lê `memberships.status` a cada requisição) | `HttpIsolationTest::test_revoked_membership_loses_access_immediately` |

### Projetado, ainda não implementado

| Item | Etapa |
|---|---|
| Interface web de onboarding (hoje só JSON; o link do convite aponta para `/invitations/accept#token=...`, rota que só existe como `POST`) | 4 |
| Convite de demais profissionais pelo administrador institucional e gestão de escopos | 4 |
| Endpoint para revogar vínculo (hoje só alterando `memberships.status` no banco de controle) | 4 |
| Cadastro e verificação automatizada de domínio próprio (`institution_domains.verified_at` é preenchido manualmente) | 3 (pendência) |
| Importação de alunos/turmas em lote | 4 |
| Redefinição de senha, códigos de recuperação de MFA, controle de sessões simultâneas | 2 (pendência) |
| Transições para `offboarding` e `closed` | 9–10 |
| Exportação dos dados da instituição para devolução | 8–9 |
| Exclusão do banco, da conta e da DEK; anonimização de alunos | 9–10 |
| Política de retenção e descarte de backups | 9 |
| Acesso emergencial de suporte a dados de um tenant | 9 |

## Onboarding: fluxo atual

| # | Quem | Ação | Resultado |
|---|---|---|---|
| 1 | Operador (manual) | Cria plano em `plans`, se houver limite contratado | Sem endpoint; direto no banco de controle |
| 2 | Administrador da plataforma | `POST /admin/institutions` com `name`, `admin_email`, `plan_code` opcional | Banco, conta, DEK e esquema criados; `status = active` (ver `PROVISIONAMENTO_INSTITUICOES.md`) |
| 3 | Sistema | Envia e-mail de convite ao `admin_email` com papel `institution_admin` | `invitations` guarda só o SHA-256 do token |
| 4 | Convidado | `POST /invitations/accept` com `token`, `name`, `password` | Cria `users` (se o e-mail ainda não existir) e `memberships` ativo |
| 5 | Convidado | `POST /login` → `POST /mfa/enroll` → `POST /mfa/confirm` | Sessão com segundo fator |
| 6 | Administrador institucional | `POST /app/schools`; `POST /app/classes/{class}/students` | Escolas e alunos |

Lacuna prática entre os passos 5 e 6: **não há endpoint para criar ano letivo nem turma**, nem para convidar outros profissionais ou atribuir `staff_scopes`. Hoje isso só é feito por `database/seeders/DemoScenarioBuilder.php` (dados fictícios). O onboarding completo de uma instituição real ainda não é possível só pela API.

### Regras do convite

- Token de 256 bits aleatórios; validade `INVITATION_TTL_HOURS` (padrão 72 h); uso único (`accepted_at`).
- Novo convite para o mesmo e-mail na mesma instituição expira os anteriores pendentes.
- O token vai no fragmento da URL (`#token=`), que não é enviado ao servidor nem aparece em logs de acesso.
- Convite só é aceito se a instituição estiver `active`.
- Senha: mínimo 12 caracteres, com letras e números (`config/security.php`).
- Se já existir usuário com o e-mail convidado, `name` e `password` enviados são ignorados e o vínculo é anexado à conta existente (o endpoint ainda exige os dois campos).
- Um usuário tem um único papel por instituição; aceitar novo convite substitui o papel e reativa o vínculo.
- Eventos de auditoria: `invitation.created`, `invitation.accepted`.

### Domínio próprio

Hosts listados em `PLATFORM_HOSTS` atendem qualquer instituição. Qualquer outro host só é aceito se existir em `institution_domains` com `verified_at` preenchido, e passa a servir **apenas** a instituição dona (`ResolverAndQuotaTest::test_verified_domain_binds_requests_to_its_institution_only`). Não há prova automatizada de propriedade (DNS/arquivo): quem preenche `verified_at` é um operador, e a responsabilidade pela verificação é dele.

## Ciclo de vida da instituição

| Estado | Como se chega hoje | Efeito |
|---|---|---|
| `provisioning` | início de `provision()` | inacessível |
| `active` | fim do provisionamento; `reactivate` | acessível |
| `suspended` | `POST /admin/institutions/{id}/suspend` | acesso e jobs negados; dados intactos; ainda recebe `tenants:migrate` |
| `failed` | exceção no provisionamento | inacessível; conta de banco removida; retomável por `resume()` |
| `offboarding` | **nenhum código atribui** | — |
| `closed` | **nenhum código atribui** | — |

## Offboarding: situação real

Não existe procedimento, endpoint, comando nem teste de encerramento. O máximo disponível é a **suspensão**, que bloqueia o acesso sem apagar nada. Consequências a declarar a clientes e ao encarregado de dados:

- pedidos de exclusão ou devolução de dados hoje exigiriam operação manual no servidor de banco, sem roteiro validado nem trilha de auditoria específica;
- `students.status` admite `anonymized` no comentário da migration, mas nenhum código anonimiza;
- `TenantDatabaseAdmin::dropDatabase()` e `dropUser()` existem como primitivas; `dropDatabase()` não é chamado por nenhum fluxo;
- `users` e `memberships` de profissionais ficam no banco de controle e não seriam removidos pela exclusão do banco do tenant;
- `audit_events` referencia `institution_id` e não tem política de retenção definida.

Desenho pretendido (Etapas 9–10, sujeito a revisão): `active → offboarding` (somente leitura/exportação) `→ closed` (banco e conta removidos, DEK destruída, vínculos revogados), com descarte de backups conforme política de retenção. Nada disso deve ser tratado como existente.
