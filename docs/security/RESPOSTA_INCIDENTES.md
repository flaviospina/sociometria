# Plano de resposta a incidentes

Plano enxuto, compatível com o que o sistema oferece hoje. Não substitui orientação jurídica: obrigações legais, prazos e conteúdo de comunicações devem ser **validados com assessoria jurídica**.

## 1. Estado

### Implementado e testado

| Recurso útil em um incidente | Código | Teste |
|---|---|---|
| Suspender uma instituição sem afetar as demais e sem apagar dados: `POST /admin/institutions/{publicId}/suspend` (reverso: `/reactivate`) | `app/Http/Controllers/Admin/InstitutionController.php` | `HttpIsolationTest::test_suspending_one_institution_does_not_block_the_others` |
| Revogação de vínculo com efeito na requisição seguinte (o estado é revalidado sempre) | `app/Tenancy/TenantResolver.php` | `HttpIsolationTest::test_revoked_membership_loses_access_immediately` |
| Conta desativada não autentica | `app/Http/Controllers/Auth/LoginController.php` | `LoginTest::test_disabled_user_cannot_log_in` |
| Jobs de instituição suspensa ou de vínculo revogado são descartados | `app/Tenancy/Queue/InTenantContext.php` | `TenantJobTest::test_job_for_suspended_or_unknown_institution_is_discarded`, `test_job_on_behalf_of_user_requires_still_active_membership` |
| Trilha de auditoria com cadeia de hash e verificação: `AuditLogger::verifyChain()` | `app/Services/AuditLogger.php` | `AuditTest::test_hash_chain_detects_tampering_and_deletion` |
| Identificador de correlação em respostas (`X-Correlation-Id`), logs e auditoria | `app/Http/Middleware/AssignCorrelationId.php` | `LoginTest::test_responses_carry_security_headers_and_health_endpoint_is_minimal` |

### Projetado, ainda não implementado

| Item | Etapa |
|---|---|
| Rota ou tela para revogar vínculo e desativar usuário (hoje só por alteração direta no banco de controle) | 4 |
| Comando/agendamento para `verifyChain` (hoje só via `php artisan tinker`); cópia externa da auditoria | 9 |
| Monitoramento, alertas, detecção automática | 9 |
| Backups e restauração testada; rotação de `APP_KEY`, de chaves de dados e de senhas de tenants | 9 |
| Encerramento em massa de sessões por tela (hoje: apagar linhas de `sessions`) | 9 |
| Acesso emergencial de suporte com registro | 9 |
| Servidor de produção, firewall, logs de sistema | 10 |

**Consequência prática:** hoje a detecção depende de consulta manual à auditoria e de relato de usuários. Não há backup: perda de dados é irrecuperável. O sistema não deve receber dados reais antes das Etapas 9 e 10.

## 2. Classificação

| Nível | Critério | Exemplos | Início da resposta |
|---|---|---|---|
| **S1 Crítico** | Exposição confirmada ou provável de dados de alunos; acesso entre instituições; comprometimento do servidor ou de `APP_KEY` | Dump do banco vazado; credencial do provisionador exposta; profissional de A lendo dados de B | Imediato |
| **S2 Alto** | Conta administrativa comprometida; acesso entre escolas da mesma instituição; cadeia de auditoria quebrada | Sessão de administrador institucional roubada; `verifyChain` retorna id | No mesmo dia |
| **S3 Médio** | Tentativa sem sucesso com sinal de ataque dirigido; indisponibilidade prolongada | Sequência de `auth.login` `denied`; fila parada | Próximo dia útil |
| **S4 Baixo** | Falha sem exposição | Vulnerabilidade em dependência sem exploração | Ciclo normal |

Na dúvida entre dois níveis, usar o mais alto. Incidente com dados de crianças e adolescentes nunca é classificado abaixo de S2.

## 3. Papéis

| Papel | Responsabilidade |
|---|---|
| Coordenador do incidente (plataforma) | Decide classificação, contenção e encerramento; mantém a linha do tempo |
| Responsável técnico (plataforma) | Executa contenção, coleta evidências, corrige |
| Contato da instituição (controladora) | Recebe a comunicação da plataforma; decide sobre comunicação a titulares e à ANPD |
| Encarregado de dados da instituição | Canal com titulares e ANPD |
| Assessoria jurídica | Valida obrigação, prazo e conteúdo de comunicações |

Nomes e contatos devem ser preenchidos antes da Etapa 10. Enquanto a equipe for de uma pessoa, coordenador e responsável técnico coincidem; registrar isso como risco.

## 4. Procedimento

### 4.1 Identificação e registro

1. Abrir registro com data/hora, quem relatou, sintomas e `X-Correlation-Id` quando houver.
2. Consultar `audit_events` (banco de controle) por `correlation_id`, `actor_user_id`, `institution_id` e `action`. Ações registradas hoje: `auth.login`, `auth.mfa`, `auth.mfa_enrolled`, `auth.logout`, `admin.access`, `tenant.resolve`, `tenant.select`, `job.context`, `institution.provisioned`, `institution.suspended`, `institution.reactivated`, `tenant.migrated`, `invitation.created`, `invitation.accepted`, `school.created`, `student.created`, `students.listed`.
3. Verificar a integridade da trilha **antes** de confiar nela (ver 4.3).

Limite: a auditoria guarda IP truncado (/24 ou /48) e não registra leituras de escolas e turmas nem negações por escopo de escola.

### 4.2 Contenção

| Situação | Ação disponível hoje |
|---|---|
| Instituição comprometida ou sob suspeita | `POST /admin/institutions/{publicId}/suspend` (administrador da plataforma com MFA). Bloqueia todo acesso e descarta jobs; **não apaga dados** |
| Conta de profissional comprometida | Banco de controle: `users.status = 'disabled'` e/ou `memberships.status = 'revoked'`; apagar as linhas do usuário em `sessions`. Não há rota para isso |
| Segundo fator comprometido | Banco de controle: limpar `mfa_secret` e `mfa_confirmed_at`; o usuário recadastra no próximo login. Trocar a senha em conjunto |
| Convite interceptado | Emitir novo convite (invalida os pendentes para o mesmo e-mail) ou expirar a linha em `invitations` |
| Suspeita sobre o servidor | Retirar a aplicação do ar; preservar disco e logs antes de qualquer correção. Procedimento de servidor a definir na Etapa 10 |
| Credencial de banco de um tenant exposta | Suspender a instituição; trocar a senha da conta no banco e atualizar `institutions.db_password` (procedimento manual, não automatizado) |

Toda alteração direta no banco durante a contenção deve ser anotada no registro do incidente: ela não passa pelo `AuditLogger`.

### 4.3 Verificação da cadeia de auditoria

```
php artisan tinker
>>> app(App\Services\AuditLogger::class)->verifyChain();
```

- `null`: cadeia íntegra.
- inteiro: id do primeiro evento inconsistente (alterado, ou posterior a um evento removido).

O que a verificação **não** detecta: remoção dos eventos mais recentes (o fim da cadeia) e reescrita completa da cadeia por quem tenha acesso de escrita ao banco de controle. A conta de banco da aplicação pode fazer `UPDATE`/`DELETE` em `audit_events`. Em incidente S1 com suspeita de acesso ao servidor, a trilha é indício, não prova.

### 4.4 Erradicação

1. Identificar a causa (falha de código, credencial, configuração, dependência).
2. Corrigir com teste automatizado que reproduza a falha, quando for de código.
3. Rotacionar o que pode ter sido exposto: senhas de usuários afetados, segredo TOTP, senha de banco do tenant, credencial do provisionador, `APP_KEY`.
   - Trocar `APP_KEY` exige recifrar `institutions.db_password`, `institutions.data_key` e `users.mfa_secret`. **Não existe ferramenta para isso**; trocar a chave sem recifrar torna os dados dos tenants inacessíveis. Planejar na Etapa 9.
4. Executar `composer audit` e atualizar dependências, se for o caso.

### 4.5 Recuperação

1. Reativar a instituição: `POST /admin/institutions/{publicId}/reactivate`.
2. Recriar acessos por novo convite.
3. Acompanhar a auditoria da instituição nos dias seguintes.
4. Restauração de dados: **indisponível** — não há backup (Etapa 9). Quando existir, restaurar sempre em banco novo e conferir o `public_id` da instituição antes de trocar a referência, para não sobrepor dados de um tenant com os de outro.

### 4.6 Comunicação

- A plataforma atua, por hipótese a validar em contrato, como **operadora**; a **instituição de ensino é a controladora**.
- A plataforma comunica a instituição afetada sem demora, com: o que ocorreu, período, dados e titulares possivelmente afetados, medidas adotadas e medidas recomendadas.
- A comunicação à ANPD e aos titulares (alunos e responsáveis), em incidente que possa acarretar risco ou dano relevante, é obrigação da controladora, com apoio técnico da operadora. **Se a comunicação é devida, em que prazo e com qual conteúdo: validar com assessoria jurídica.** Este documento não fixa prazos legais.
- Comunicações nunca incluem dados de alunos além do estritamente necessário, nem são enviadas por canal não acordado com a instituição.
- Incidentes que atinjam mais de uma instituição: cada uma é comunicada separadamente, sem revelar a identidade das demais.

### 4.7 Encerramento

Registro final com linha do tempo, causa, dados afetados, ações, comunicações feitas e pendências. Revisar `MODELO_AMEACAS.md` e a matriz ASVS se o incidente revelar ameaça ou controle não previsto.

## 5. Pendências antes de produção

- Nomear responsáveis e contatos (seção 3).
- Definir com cada instituição, em contrato, canal e fluxo de comunicação de incidentes.
- Backups, monitoramento e ferramenta de rotação de chaves (Etapa 9).
- Simular um incidente S1 em homologação (Etapa 10).
