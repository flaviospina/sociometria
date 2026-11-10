# Proteção de dados pessoais (LGPD)

Orientações **técnicas** sobre como o sistema trata dados pessoais hoje e o que está planejado. Não é parecer jurídico. A definição de hipótese legal, papéis, prazos, avisos e relatório de impacto cabe à instituição de ensino e à assessoria jurídica; os pontos marcados "a validar" dependem dessa decisão.

## 1. Estado

### Implementado e testado

| Medida | Código | Teste (em `tests/Feature`) |
|---|---|---|
| Dados de cada instituição em banco próprio, com credencial própria | `app/Tenancy/Provisioning/*` | `Tenancy/ProvisioningTest::test_tenant_credential_cannot_read_control_database_or_other_tenants` |
| Nome, data de nascimento e código de matrícula do aluno cifrados com chave da instituição | `app/Models/Tenant/Student.php`, `app/Tenancy/TenantEncrypter.php` | `Tenancy/ContextSwitchingTest::test_student_identifiers_are_encrypted_at_rest_with_per_institution_key` |
| Nenhum dado identificável de aluno no banco de controle nem na auditoria | `app/Services/AuditLogger.php`, `database/migrations/2026_11_10_000001_create_control_plane_tables.php` | `Tenancy/ResolverAndQuotaTest::test_student_created_over_http_is_stored_encrypted_and_never_in_audit_log` |
| Acesso de profissionais limitado a escola e turma | `app/Services/SchoolAccess.php` | `Tenancy/HttpIsolationTest::test_director_cannot_access_other_school_of_same_institution`, `test_teacher_reaches_only_assigned_class` |
| Equipe da plataforma sem acesso padrão a dados de alunos | `app/Http/Controllers/Admin/InstitutionController.php` | `HttpIsolationTest::test_platform_admin_has_no_default_access_to_institutional_data` |
| Listagem de alunos sempre auditada (quem, quando, qual turma, quantos — sem nomes) | `app/Http/Controllers/Tenant/StudentController.php` | `ResolverAndQuotaTest::test_student_created_over_http_is_stored_encrypted_and_never_in_audit_log` |
| IP truncado na auditoria | `AuditLogger::minimizeIp` | `AuditTest::test_origin_address_is_minimized` |
| Suspender instituição não apaga dados | `InstitutionController::suspend` | `HttpIsolationTest::test_suspending_one_institution_does_not_block_the_others` (verifica o bloqueio; a preservação decorre de o código só alterar o estado) |
| Respostas com `Cache-Control: no-store` | `app/Http/Middleware/SecurityHeaders.php` | `Auth/LoginTest::test_responses_carry_security_headers_and_health_endpoint_is_minimal` |

### Projetado, ainda não implementado

| Item | Etapa |
|---|---|
| Registro de finalidade e de hipótese legal por instituição | 4 |
| Versões de aviso de privacidade e registro de ciência | 4–5 |
| Cadastro de responsáveis legais e, se a instituição optar, registro de consentimento | 4–5 |
| Coleta de respostas a questionários (dado mais sensível do sistema) | 5 |
| Atendimento a direitos do titular: acesso, correção, exportação, eliminação | 4–9 |
| Retenção configurável e fluxo de exclusão/anonimização (ver `RETENCAO_EXCLUSAO.md`) | 4–9 |
| Backups, registro de operações de suporte, monitoramento | 9 |
| Contrato de operação de dados, relatório de impacto, definição do encarregado | Antes da Etapa 10 (jurídico) |

O sistema ainda não tem interface nem servidor configurado e **não deve receber dados reais**.

## 2. Papéis (hipótese a validar em contrato)

| Agente | Papel provável | Observação |
|---|---|---|
| Instituição de ensino | **Controladora** | Decide finalidade, hipótese legal, quem responde, por quanto tempo guardar |
| Plataforma | **Operadora** | Trata os dados conforme instruções da instituição |
| Provedor de hospedagem | Suboperador | A definir na Etapa 10 |

Para os dados dos profissionais (contas de acesso) e dados de faturamento, a plataforma pode ser controladora. A qualificação definitiva depende do contrato e da prática; validar com assessoria jurídica.

## 3. Dados efetivamente tratados hoje

Fonte: migrations em `database/migrations` e `database/migrations/tenant`.

| Titular | Dado | Onde | Proteção |
|---|---|---|---|
| Aluno | Nome | Banco da instituição, `students.name` | Cifrado (chave da instituição) |
| Aluno | Data de nascimento (opcional) | `students.birth_date` | Cifrado |
| Aluno | Código de matrícula (opcional) | `students.registration_code` | Cifrado |
| Aluno | Escola, turma, situação da matrícula | `students.school_id`, `enrollments` | Em claro (sem identificação direta) |
| Profissional | Nome, e-mail, senha (hash), segredo TOTP (cifrado) | Banco de controle, `users` | Senha com hash; segredo cifrado com `APP_KEY` |
| Profissional | Papel e vínculo com a instituição; escopo por escola/turma | `memberships` (controle), `staff_scopes` (instituição) | Em claro |
| Profissional convidado | E-mail e papel | `invitations` | Em claro; só o hash do token é guardado |
| Profissional | IP completo e user agent da sessão | `sessions` (controle) | Sessão cifrada quando `SESSION_ENCRYPT=true`; as colunas `ip_address` e `user_agent` ficam em claro |
| Profissional | Ações, com IP truncado | `audit_events` (controle) | Cadeia de hash |

Não são coletados hoje: foto, endereço, documento, dados de saúde, raça, religião, dados de responsáveis, respostas a questionários. **Não acrescentar campo de aluno sem finalidade registrada.**

Observação: a ordenação de alunos por nome é feita em memória depois de decifrar; não há índice nem busca sobre os campos cifrados.

## 4. Crianças e adolescentes (art. 14)

- Todo tratamento deve atender ao **melhor interesse** da criança e do adolescente. Esse é o critério de projeto: uma função que não sirva ao acompanhamento pedagógico e à convivência não deve existir.
- **Não presumir que o consentimento é a única hipótese legal.** A instituição, com sua assessoria jurídica, define a hipótese aplicável a cada finalidade (consentimento dos responsáveis é uma possibilidade, não a única). O sistema deve suportar a escolha, não impô-la. Hoje ele não registra hipótese nem consentimento.
- Avisos destinados a alunos e responsáveis devem ser simples e claros. Não há aviso implementado.
- Dados sociométricos (quem escolhe ou rejeita quem) podem revelar isolamento e conflitos. Quando existirem (Etapa 5 em diante), serão tratados como de alto risco, independentemente da classificação legal formal.

## 5. O que o sistema não faz

Verificado no código atual (não há rotas, modelos nem dependências para isso):

- não exibe publicidade nem compartilha dados com terceiros para esse fim;
- não cria perfil comportamental para fins comerciais;
- não produz ranking de alunos (não há motor sociométrico; quando houver, ranking de popularidade está fora do escopo de projeto);
- não toma decisão automatizada sobre aluno;
- não permite acesso de alunos ou responsáveis (estudantes não têm conta);
- não envia dados para serviços externos — a única saída é o e-mail de convite a profissionais.

Esta lista deve ser reavaliada a cada etapa; os três primeiros itens são compromissos de projeto.

## 6. Princípios e situação

| Princípio | Situação |
|---|---|
| Finalidade e adequação | **Pendente**: finalidade não é registrada no sistema (Etapa 4) |
| Necessidade (minimização) | Atendido no que existe: três campos de aluno, dois opcionais; auditoria sem nomes; IP truncado. Exceção: `sessions` guarda IP completo |
| Livre acesso, qualidade | **Pendente**: sem exportação por titular e sem edição de aluno (Etapa 4) |
| Transparência | **Pendente**: sem aviso de privacidade (Etapas 4–5) |
| Segurança | Parcial: ver `docs/security/MODELO_AMEACAS.md` e riscos residuais |
| Prevenção | Parcial: testes de isolamento no CI; sem monitoramento |
| Não discriminação | Compromisso de projeto (seção 5); relevante a partir da Etapa 6 |
| Responsabilização | Parcial: auditoria e esta documentação; sem relatório de impacto |

## 7. Direitos dos titulares

Pedidos de alunos e responsáveis são dirigidos à instituição (controladora); a plataforma dá apoio técnico. Hoje **nenhum** direito é atendido por função do sistema: não há edição, exportação, anonimização nem exclusão de aluno. Qualquer atendimento exigiria operação manual no banco da instituição, o que não é aceitável em produção.

## 8. Riscos residuais relevantes para privacidade

1. `APP_KEY` fica na mesma máquina que os dados: a cifra protege contra vazamento do banco ou de dump, não contra comprometimento do servidor.
2. A credencial de provisionamento, com alcance sobre todos os bancos de tenants, está no mesmo `.env` do processo web.
3. A auditoria pode ser alterada no banco por quem tenha a credencial de controle; a cadeia de hash detecta a maior parte das adulterações, não todas.
4. A separação entre escolas da mesma instituição é lógica: mesmo banco, mesma chave.
5. Cabeçalhos e CSP não foram validados com interface; CSRF não tem teste dedicado.
6. Não há backup nem plano de continuidade.

## 9. Decisões que cabem à instituição e ao jurídico

- Hipótese legal para cada finalidade e forma de participação dos responsáveis.
- Texto dos avisos de privacidade para responsáveis e para alunos.
- Prazos de retenção e destino dos dados ao fim do contrato.
- Necessidade e conteúdo do relatório de impacto.
- Encarregado e canal de atendimento a titulares.
- Cláusulas do contrato de operação, inclusive suboperadores e local de hospedagem.
