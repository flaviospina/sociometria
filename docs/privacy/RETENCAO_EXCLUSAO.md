# Retenção e exclusão de dados

Orientações **técnicas**. Prazos de guarda, hipótese legal para conservação e forma de eliminação são decisões da instituição de ensino (controladora, por hipótese a validar em contrato) com sua assessoria jurídica. Nenhum prazo deste documento é prazo legal.

## 1. Estado

### Implementado e testado

| Regra | Código | Teste (em `tests/Feature`) |
|---|---|---|
| Suspender uma instituição bloqueia o acesso e **não apaga nem altera dados** (só muda `status` e `suspended_at`) | `app/Http/Controllers/Admin/InstitutionController.php` (`suspend`, `reactivate`) | `Tenancy/HttpIsolationTest::test_suspending_one_institution_does_not_block_the_others` |
| Nenhuma exclusão automática: não existe job, comando nem agendamento que apague dados; `subscription_status` não é lido por nenhum código | `routes/console.php`, `app/Console/Commands/` | — (ausência de código) |
| Provisionamento com falha preserva o banco (ainda sem dados reais) e remove apenas a conta de acesso | `app/Tenancy/Provisioning/InstitutionProvisioner.php` | `Tenancy/ProvisioningTest::test_failed_provisioning_never_leaves_an_accessible_institution`, `test_failed_provisioning_can_be_resumed` |
| Eventos de auditoria não são alterados nem excluídos pela aplicação | `app/Models/Control/AuditEvent.php` | `AuditTest::test_audit_events_are_immutable_through_the_application` |
| Convite: validade limitada (72 h por padrão), uso único; só o hash do token é guardado | `app/Services/InvitationService.php`, `config/tenancy.php` | `Tenancy/ResolverAndQuotaTest::test_onboarding_through_admin_api_and_invitation` |
| Sessão: expiração por inatividade (30 min no `.env.example`; 120 min no padrão de `config/session.php`) | `config/session.php` | — |

### Projetado, ainda não implementado

| Item | Etapa |
|---|---|
| Edição, inativação e anonimização de aluno; exclusão de escola e turma | 4 |
| Prazos de retenção configuráveis por instituição | 4 (estrutura) e 9 (execução) |
| Retenção e descarte de respostas a questionários | 5 |
| Retenção de resultados e de relatórios/PDF gerados | 6–8 |
| Fluxo de encerramento de contrato (devolução, eliminação, comprovante) | 9 |
| Política de retenção e descarte de backups | 9 |
| Rotação e descarte de logs técnicos e de auditoria | 9 |

Vestígios no esquema que **ainda não têm lógica**: o valor `anonymized` previsto no comentário de `students.status`; os estados `offboarding` e `closed` em `app/Enums/InstitutionStatus.php`; o método `TenantDatabaseAdmin::dropDatabase`, que não é chamado por nenhum código da aplicação.

## 2. Regras já adotadas

1. **Suspender não é excluir.** A suspensão é reversível e preserva todos os dados.
2. **Nunca excluir automaticamente por expiração ou inadimplência de assinatura.** A eliminação de dados de uma instituição só ocorrerá por ato explícito, autorizado e auditado.
3. **A exclusão é decisão da controladora.** A plataforma executa mediante instrução registrada.
4. **Auditoria não contém dados de alunos**, o que permite conservá-la após a eliminação dos dados da instituição, se a hipótese de guarda for validada.

As regras 1 e 2 estão refletidas no código atual. A 3 e a 4 são diretrizes para as etapas seguintes.

## 3. Inventário e situação de retenção hoje

| Dado | Local | Retenção hoje | Como seria removido hoje |
|---|---|---|---|
| Alunos (nome, nascimento, matrícula — cifrados) | Banco da instituição, `students` | Indefinida | Só por operação manual no banco |
| Matrículas, turmas, anos letivos, escolas | Banco da instituição | Indefinida | Idem |
| Escopos de profissionais | Banco da instituição, `staff_scopes` | Indefinida | Idem |
| Contas de profissionais | Controle, `users` | Indefinida; `status = disabled` bloqueia sem apagar | Idem |
| Vínculos | Controle, `memberships` | Indefinida; `status = revoked` bloqueia sem apagar | Idem |
| Convites | Controle, `invitations` | Expiram em 72 h, mas a linha (com e-mail) permanece | Idem |
| Sessões (IP completo, user agent) | Controle, `sessions` | Removidas pela coleta de lixo do Laravel após expirarem | Automática |
| Auditoria | Controle, `audit_events` | Indefinida | Não deve ser removida; excluir quebra a cadeia de hash |
| Chave de dados e credencial do banco da instituição | Controle, `institutions` | Enquanto a linha existir | Manual |
| Logs técnicos | `storage/logs` | Conforme canal de log; sem política definida | Manual |
| Jobs e jobs com falha (payload cifrado) | Controle, `jobs`, `failed_jobs` | Jobs concluídos saem da fila; falhos ficam | Manual |
| Cache | Controle, `cache` | TTL de cada chave | Automática |
| Backups | — | **Não existem** | — |

Conclusão: hoje a retenção é, na prática, indefinida, e nenhuma remoção de dado de aluno pode ser feita pelo sistema. Isso precisa estar resolvido antes do uso com dados reais.

## 4. Projeto do fluxo de exclusão e anonimização (não implementado)

### 4.1 Aluno (Etapa 4)

- **Inativar** (`status = inactive`): sai das turmas ativas e libera quota; dados preservados.
- **Anonimizar** (`status = anonymized`): substituir nome, data de nascimento e código de matrícula por valores neutros; manter o registro para não quebrar referências.
- **Excluir**: remover o registro e suas matrículas, quando não houver dado dependente.
- Toda operação auditada com `public_id`, sem nome.

Ponto em aberto para a Etapa 5–6: respostas sociométricas referem-se a **outros** alunos (escolhas e rejeições). Anonimizar um aluno em turma pequena pode não impedir a reidentificação pelos colegas. O critério deve ser definido antes de coletar respostas, não depois.

### 4.2 Instituição (Etapa 9)

1. Pedido formal da controladora → estado `offboarding`, acesso restrito.
2. Devolução dos dados em formato aberto, se solicitada.
3. Período de carência configurável, reversível.
4. Eliminação: `DROP DATABASE` do tenant, remoção da conta de banco e destruição da chave de dados (`data_key`).
5. Estado `closed`; a linha em `institutions` e a auditoria permanecem como comprovante, sem dados de alunos.
6. Comprovante de eliminação para a instituição.

A destruição da chave de dados torna ilegíveis os campos cifrados que restarem em cópias — desde que a chave não esteja também nessas cópias. Como `data_key` fica no banco de controle cifrada com `APP_KEY`, um backup do banco de controle somado à `APP_KEY` recupera a chave. O apagamento criptográfico só será confiável quando a política de backups (Etapa 9) tratar disso.

### 4.3 Profissional (Etapa 4)

- Revogar vínculo e desativar conta por função do sistema (hoje, só no banco).
- Conta sem nenhum vínculo ativo: definir prazo para remoção de nome e e-mail.
- `audit_events.actor_user_id` guarda só o id numérico; após a remoção da conta, o evento deixa de identificar a pessoa diretamente.

### 4.4 Retenção configurável (Etapas 4 e 9)

- Prazos por categoria de dado, definidos por instituição dentro de limites da plataforma.
- Execução por job agendado (`TenantJob`), com relatório prévio do que será removido e confirmação da instituição nas primeiras execuções.
- Nunca disparada por estado de assinatura.

## 5. Sugestões de prazo (a validar)

Ponto de partida para discussão com a instituição e o jurídico; nenhum é exigência legal afirmada por este documento.

| Categoria | Sugestão técnica | Decide |
|---|---|---|
| Cadastro de aluno | Enquanto durar o vínculo com a escola e o contrato | Instituição |
| Respostas individuais a questionários | O menor prazo que atenda à finalidade pedagógica | Instituição + jurídico |
| Resultados agregados | Pode exceder o das respostas se não permitir reidentificação | Instituição + jurídico |
| Relatórios/PDF gerados | Curto; regeráveis sob demanda | Instituição |
| Auditoria | Prazo compatível com apuração de incidentes e responsabilização | Plataforma + jurídico |
| Sessões e logs técnicos | Dias a poucas semanas | Plataforma |
| Convites expirados | Remover e-mail após a expiração | Plataforma |
| Backups | Janela curta e definida; descarte verificável | Plataforma + contrato |

## 6. Pendências antes de dados reais

- Implementar inativação, anonimização e exclusão de aluno (Etapa 4).
- Definir o tratamento de respostas de alunos anonimizados (antes da Etapa 5).
- Limpeza de `invitations` expirados e política para `failed_jobs` e logs (Etapa 9).
- Fluxo de encerramento com comprovante e política de backups coerente com a eliminação (Etapa 9).
- Cláusulas contratuais sobre devolução e eliminação ao fim do contrato (jurídico).
